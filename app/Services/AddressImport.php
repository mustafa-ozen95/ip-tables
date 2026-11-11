<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Highlight;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\SubGroup;
use Illuminate\Support\Facades\DB;

/**
 * Sekmeyle ayrılmış listeden (TSV) toplu adres yükleme (bkz.
 * addresses:import). Sütunlar: grup, alt grup, adres, ad, açıklama, vurgu.
 *
 * Grup VAR OLMALIDIR (yetki sınırıdır, burada açılmaz) ve adresin türünü
 * kabul etmelidir; alt grup yoksa açılır. Kayıtlar elle eklemenin yolundan
 * geçer (IpAddressWriter): her biri işlem geçmişine düşer. IPv4'te /24 blok eklenir; daha küçüğü (ör. /25) kendi
 * /24'ünün parçası olarak açılır, blok listede yoksa adsız açılır.
 *
 * Parçalar birbirine ya da kayıtlı parçaya binemez; bu da yazmadan önce
 * denetlenir.
 *
 * Önce bütün satırlar denetlenir; tek hata varsa hiçbir şey yazılmaz. Yazma
 * tek işlemdir: yarıda kalırsa silinenler de geri gelir.
 */
class AddressImport
{
    public function __construct(
        private IpAddressParser $parser,
        private IpAddressWriter $writer,
        private Ipv4SplitLayout $layout,
    ) {}

    /**
     * @return array{rows: list<array{line: int, group: string, subGroup: string|null, parsed: ParsedAddress,
     *               name: string|null, description: string|null, highlight: string|null}>, errors: list<string>}
     */
    public function read(string $contents, bool $replace): array
    {
        $rows = [];
        $errors = [];
        $seen = [];
        /** @var array<string, list<array{start: int, end: int, notation: string}>> $taken grup|/24 => listedeki parçalar */
        $taken = [];

        foreach (preg_split('/\R/u', $contents) as $i => $line) {
            $cells = array_map(fn (string $c): string => trim($c), explode("\t", $line));

            if (trim($line) === '' || str_starts_with($cells[0], '#') || ($i === 0 && mb_strtolower($cells[0]) === 'grup')) {
                continue;
            }

            [$group, $subGroup, $address, $name, $description, $highlight] = array_pad($cells, 6, '');
            $at = 'Satır '.($i + 1).': ';
            $parsed = $this->parser->parse($address);
            $model = AddressGroup::query()->where('name', $group)->first();
            $error = match (true) {
                $model === null => "\"{$group}\" adlı grup yok; grup önce Gruplar ekranından açılır.",
                $parsed === null => "\"{$address}\" geçerli bir adres değil.",
                ! $model->kind->accepts($parsed->kind) => "{$parsed->notation()} {$group} grubuna uymuyor ({$model->kind->label()}).",
                $this->isIpv4($parsed) && $parsed->prefix < Ipv4BlockRules::BLOCK_PREFIX => "{$parsed->notation()}: IPv4'te en büyük blok /24.",
                isset($seen[$group.'|'.$parsed->notation()]) => "{$parsed->notation()} listede iki kez var.",
                ! $replace && $this->exists($group, $parsed) => "{$parsed->notation()} {$group} grubunda zaten kayıtlı.",
                $this->isPart($parsed) && ($clash = $this->clash($group, $parsed, $taken, ! $replace)) !== null => "{$parsed->notation()}, {$clash} ile çakışıyor.",
                mb_strlen($name) > 120 => 'ad 120 karakteri geçiyor.',
                mb_strlen($description) > 1000 => 'açıklama 1000 karakteri geçiyor.',
                $highlight !== '' && $this->highlight($highlight) === null => "vurgu \"{$highlight}\" bilinmiyor.",
                default => null,
            };

            if ($error !== null) {
                $errors[] = $at.$error;

                continue;
            }

            $seen[$group.'|'.$parsed->notation()] = true;

            if ($this->isPart($parsed)) {
                $taken[$group.'|'.$this->blockOf($parsed)][] = [...$this->span($parsed), 'notation' => $parsed->notation()];
            }
            $rows[] = [
                'line' => $i + 1,
                'group' => $group,
                'subGroup' => $subGroup !== '' ? $subGroup : null,
                'parsed' => $parsed,
                'name' => $name !== '' ? $name : null,
                'description' => $description !== '' ? $description : null,
                'highlight' => $highlight !== '' ? $this->highlight($highlight)?->value : null,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  read() çıktısı
     * @return array{deleted: int, created: int}
     */
    public function write(array $rows, bool $replace, string $reason): array
    {
        return DB::transaction(function () use ($rows, $replace, $reason): array {
            $deleted = 0;

            if ($replace) {
                foreach (IpAddress::query()->with('group')->get() as $record) {
                    $this->writer->delete($record, $reason);
                    $deleted++;
                }
            }

            /* Önce bloklar ve tek adresler, sonra parçalar: parça bloğunu bulsun. */
            usort($rows, fn (array $a, array $b): int => $this->isPart($a['parsed']) <=> $this->isPart($b['parsed']));

            foreach ($rows as $row) {
                $this->isPart($row['parsed'])
                    ? $this->createPart($row)
                    : $this->writer->create($this->payload($row, $row['parsed']));
            }

            return ['deleted' => $deleted, 'created' => count($rows)];
        });
    }

    /**
     * Parçanın bindiği parça: önce listede, sonra ($stored) kayıtlılarda.
     * Aynı ağ çakışma değildir (güncellenir ya da "zaten kayıtlı" der).
     *
     * @param  array<string, list<array{start: int, end: int, notation: string}>>  $taken
     */
    private function clash(string $group, ParsedAddress $parsed, array $taken, bool $stored): ?string
    {
        ['start' => $start, 'end' => $end] = $this->span($parsed);
        $overlaps = fn (array $o): bool => $o['start'] <= $end && $start <= $o['end'] && $o['notation'] !== $parsed->notation();

        foreach ($taken[$group.'|'.$this->blockOf($parsed)] ?? [] as $other) {
            if ($overlaps($other)) {
                return "listedeki {$other['notation']}";
            }
        }

        if (! $stored) {
            return null;
        }

        $block = $this->blockOf($parsed);
        $records = IpAddress::query()->whereHas('group', fn ($q) => $q->where('name', $group))
            ->where('prefix', '>', Ipv4BlockRules::BLOCK_PREFIX)
            ->where('address', 'like', substr($block, 0, (int) strrpos($block, '.') + 1).'%')->get();

        foreach ($records as $record) {
            $parsedRecord = $this->parser->parse($record->notation);

            if ($parsedRecord !== null && $this->blockOf($parsedRecord) === $block
                && $overlaps([...$this->span($parsedRecord), 'notation' => $parsedRecord->notation()])) {
                return "kayıtlı {$parsedRecord->notation()}";
            }
        }

        return null;
    }

    /** @return array{start: int, end: int} */
    private function span(ParsedAddress $parsed): array
    {
        $start = (int) ip2long($parsed->address);

        return ['start' => $start, 'end' => $start + $this->layout->size($parsed->prefix) - 1];
    }

    private function blockOf(ParsedAddress $parsed): string
    {
        return long2ip(ip2long($parsed->address) & ~0xFF);
    }

    /** @param  array<string, mixed>  $row */
    private function createPart(array $row): void
    {
        $parsed = $row['parsed'];
        $network = $this->blockOf($parsed);
        $block = $this->find($row['group'], $network, Ipv4BlockRules::BLOCK_PREFIX)
            ?? $this->writer->create($this->payload([...$row, 'name' => null, 'description' => null, 'highlight' => null],
                $this->parser->parse("{$network}/".Ipv4BlockRules::BLOCK_PREFIX)));

        $start = ip2long($parsed->address) - ip2long($network);
        $range = ['start' => $start, 'end' => $start + $this->layout->size($parsed->prefix) - 1, 'prefix' => $parsed->prefix];
        $this->writer->split($block->loadMissing(['group', 'subGroup']), [$parsed], $this->layout->describe([$range]));

        $this->writer->update($this->find($row['group'], $parsed->address, $parsed->prefix), $this->payload($row, $parsed));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> IpAddressWriter yükü
     */
    private function payload(array $row, ParsedAddress $parsed): array
    {
        $group = AddressGroup::query()->where('name', $row['group'])->firstOrFail();
        $subGroup = $row['subGroup'] === null ? null
            : SubGroup::query()->firstOrCreate(['address_group_id' => $group->getKey(), 'name' => $row['subGroup']]);

        return [
            'group' => $group,
            'subGroup' => $subGroup,
            'parsed' => $parsed,
            'details' => ['name' => $row['name'], 'description' => $row['description'], 'highlight' => $row['highlight']],
        ];
    }

    private function find(string $group, string $address, int $prefix): ?IpAddress
    {
        return IpAddress::query()->with(['group', 'subGroup'])
            ->whereHas('group', fn ($q) => $q->where('name', $group))
            ->where('address', $address)->where('prefix', $prefix)->first();
    }

    private function exists(string $group, ParsedAddress $parsed): bool
    {
        return $this->find($group, $parsed->address, $parsed->prefix) !== null;
    }

    private function isPart(ParsedAddress $parsed): bool
    {
        return $this->isIpv4($parsed) && $parsed->prefix > Ipv4BlockRules::BLOCK_PREFIX;
    }

    private function isIpv4(ParsedAddress $parsed): bool
    {
        return filter_var($parsed->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /** "red" ya da "kırmızı" */
    private function highlight(string $value): ?Highlight
    {
        $value = mb_strtolower($value);

        return Highlight::tryFrom($value)
            ?? collect(Highlight::cases())->first(fn (Highlight $h): bool => mb_strtolower($h->label()) === $value);
    }
}
