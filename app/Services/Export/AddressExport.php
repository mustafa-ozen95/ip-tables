<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\ActivityAction;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Ipv4BlockPlan;
use App\Support\Net\Cidr;

/**
 * Adres listesinin Excel çıktısı: listede görünen sırayla, bloğun altında
 * parçaları ve bölünmemiş alanı (bkz. Ipv4BlockPlan).
 *
 * Yalnız kullanıcının hem görme hem çıktı alma yetkisi olan gruplar girer;
 * grup ve arama süzgeci listedekiyle aynıdır. Dosya tarayıcıya iner,
 * sunucuda kalmaz; çıktı alındığı işlem geçmişine düşer.
 */
final class AddressExport
{
    /** @var list<string> */
    public const HEADERS = ['Grup', 'Alt grup', 'Ağ', 'Ağ geçidi', 'İlk adres', 'Son adres', 'Maske', 'Ad', 'Açıklama', 'Vurgu', 'Durum'];

    public function __construct(
        private Ipv4BlockPlan $plan,
        private ActivityRecorder $activity,
    ) {}

    /** @return array{content: string, name: string, count: int} */
    public function build(User $user, ?string $groupUuid, string $query): array
    {
        $records = IpAddress::query()->visibleTo($user)->allowedTo($user, 'export')
            ->when($groupUuid, fn ($q, string $uuid) => $q->whereHas('group', fn ($g) => $g->where('uuid', $uuid)))
            ->search($query)
            ->with(['group', 'subGroup'])
            ->ordered()
            ->get();

        $rows = $this->rows($this->plan->build($records));
        $label = $groupUuid !== null ? ($records->first()?->group?->name ?? 'Grup') : 'Hepsi';

        $this->activity->record(ActivityAction::Exported, 'Excel çıktısı alındı: '.$label
            .($query !== '' ? " · \"{$query}\" araması" : '').' · '.$records->count().' kayıt');

        return [
            'content' => Workbook::uret('IP adresleri', self::HEADERS, $rows),
            'name' => 'ip-adresleri-'.now()->format('Y-m-d-Hi').'.xlsx',
            'count' => $records->count(),
        ];
    }

    /**
     * @param  array{blocks: list<array<string, mixed>>, others: list<IpAddress>}  $plan
     * @return list<list<string|null>>
     */
    private function rows(array $plan): array
    {
        $rows = [];

        foreach ($plan['blocks'] as $block) {
            $rows[] = $this->row($block['record'], $block['stateLabel']);

            foreach ($block['parts'] as $part) {
                $rows[] = $part['free']
                    ? [$this->group($block['record']), $this->subGroup($block['record']), $part['notation'], null,
                        $part['first'], $part['last'], null, null, null, null, $part['stateLabel']]
                    : $this->row($part['record'], $part['stateLabel']);
            }
        }

        foreach ($plan['others'] as $record) {
            $rows[] = $this->row($record, null);
        }

        return $rows;
    }

    /** @return list<string|null> */
    private function row(IpAddress $record, ?string $state): array
    {
        $cidr = Cidr::make($record->address, $record->prefix);
        /* Ağ geçidi ilk kullanılabilir adrestir; /31, /32 ve tek IPv6 adreste yok. */
        $gateway = ! $cidr->isSingleHost() && ! $cidr->isPointToPoint() && ! $cidr->isIpv6 ? $cidr->firstUsable() : null;

        return [
            $this->group($record),
            $this->subGroup($record),
            $cidr->toString(),
            $gateway,
            $gateway !== null ? long2ip(ip2long($gateway) + 1) : $cidr->firstUsable(),
            $cidr->lastUsable(),
            $cidr->netmask(),
            $record->name,
            $record->description,
            $record->highlight?->label(),
            $state,
        ];
    }

    private function group(IpAddress $record): ?string
    {
        return $record->group?->name;
    }

    private function subGroup(IpAddress $record): ?string
    {
        return $record->subGroup?->name;
    }
}
