<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Models\IpAddress;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\IpAddressParser;
use App\Services\Ipv4BlockRules;
use App\Services\Ipv4SplitLayout;
use App\Services\ParsedAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * /24 bloğun boş alanını aralık aralık bölme (bkz. Ipv4BlockRules,
 * Ipv4SplitLayout). Her satır: subnet, kaç parça ("29:2"; subneti de taşır,
 * pencerede aralık listesi ona bağlı) ve o kadar parçanın sığdığı boş aralık
 * ("64-79"); boş satır atlanır. Seçenekler pencerede listelenir ama elle
 * gönderilen değer burada yeniden sınanır.
 *
 * Bölme gruba kayıt AÇAR: bloğu düzenleme yetkisi yetmez, grupta "ekle"
 * yetkisi de aranır — yoksa "düzenle" ile gruba 256 kayıt eklenebilirdi.
 */
class SplitIpAddressRequest extends FormRequest
{
    /** @var list<array{start: int, end: int, prefix: int}> */
    private array $ranges = [];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->block())
            && app(UserPermissionResolver::class)
                ->allows($this->user(), IpAddressPolicy::RESOURCE, 'create', $this->block()->address_group_id);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'ranges' => ['required', 'array', 'max:'.Ipv4SplitLayout::MAX_RANGES],
            'ranges.*' => ['array:prefix,slot,count'],
            'ranges.*.prefix' => ['nullable', 'integer', 'between:'.Ipv4SplitLayout::MIN_PREFIX.','.Ipv4SplitLayout::SPLIT_MAX_PREFIX],
            'ranges.*.slot' => ['nullable', 'string', 'max:7'],
            'ranges.*.count' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{1,3}$/'],
            'onizle' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ranges' => 'aralıklar', 'ranges.*.prefix' => 'subnet', 'ranges.*.count' => 'adet'];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $rules = app(Ipv4BlockRules::class);
            $ranges = $rules->isBlock($this->block()) ? $this->readRanges() : null;

            $error = match (true) {
                ! $rules->isBlock($this->block()) => 'Yalnız IPv4 /'.Ipv4BlockRules::BLOCK_PREFIX.' blok bölünür.',
                is_string($ranges) => $ranges,
                $ranges === [] => 'En az bir satırda subnet ve müsait aralık seçin.',
                default => app(Ipv4SplitLayout::class)->error(
                    $this->block()->address,
                    $ranges,
                    $rules->taken($this->block(), $rules->parts($this->block())),
                ),
            };

            if ($error !== null) {
                $validator->errors()->add('ranges', $error);

                return;
            }

            $this->ranges = $ranges;
        }];
    }

    public function block(): IpAddress
    {
        return $this->route('ipAddress');
    }

    /** Yalnız önizleme: kayıt açılmaz, pencere sonuçla yeniden açılır. */
    public function preview(): bool
    {
        return $this->boolean('onizle');
    }

    /** @return list<ParsedAddress> */
    public function parts(): array
    {
        $parser = app(IpAddressParser::class);

        return array_map(
            fn (string $cidr): ParsedAddress => $parser->parse($cidr),
            app(Ipv4SplitLayout::class)->parts($this->block()->address, $this->ranges),
        );
    }

    /** Kayda yazılan kısa düzen (".0–.63 /29×8 · .64–.127 /28×4"). */
    public function layout(): string
    {
        return app(Ipv4SplitLayout::class)->describe($this->ranges);
    }

    /**
     * Doldurulmuş satırlar; eksik ya da okunamayan satırda hata metni.
     *
     * @return list<array{start: int, end: int, prefix: int}>|string
     */
    private function readRanges(): array|string
    {
        $layout = app(Ipv4SplitLayout::class);
        $ranges = [];

        foreach (array_values($this->input('ranges', [])) as $i => $row) {
            $prefix = $row['prefix'] ?? null;
            $slot = trim((string) ($row['slot'] ?? ''));
            $line = $i + 1;

            if (blank($prefix) && $slot === '') {
                continue;
            }

            if (blank($prefix) || $slot === '') {
                return "{$line}. satırda subnet ve müsait aralık birlikte seçilir.";
            }

            $size = $layout->size((int) $prefix);
            [$countPrefix, $count] = array_map('intval', explode(':', filled($row['count'] ?? null) ? $row['count'] : "{$prefix}:1"));

            if ($countPrefix !== (int) $prefix || $count < 1) {
                return "{$line}. satırdaki adet /{$prefix} için değil; listeden yeniden seçin.";
            }

            if (! preg_match('/^(\d{1,3})-(\d{1,3})$/', $slot, $m) || (int) $m[2] - (int) $m[1] + 1 !== $size * $count) {
                return "{$line}. satırdaki aralık {$count} tane /{$prefix} boyunda değil; listeden yeniden seçin.";
            }

            [$start, $end] = [(int) $m[1], (int) $m[2]];

            if ($end >= Ipv4SplitLayout::BLOCK_SIZE) {
                return "{$line}. satır bloğun sonunu aşıyor: .{$start}’ten sonra en çok "
                    .intdiv(Ipv4SplitLayout::BLOCK_SIZE - $start, $size)." tane /{$prefix} sığar.";
            }

            $ranges[] = ['start' => $start, 'end' => $end, 'prefix' => (int) $prefix];
        }

        return $ranges;
    }
}
