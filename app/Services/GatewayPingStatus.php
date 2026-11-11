<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BadgeVariant;
use App\Models\IpAddress;

/**
 * Kaydın son gateway ping sonucu, ekranda nokta olarak: yeşil yanıt veriyor,
 * kırmızı vermiyor. Denetlenmeyen kayıtta (bölünmüş bloğun kendisi, IPv6, …)
 * nokta yoktur.
 */
class GatewayPingStatus
{
    /** @return array{up: bool, variant: BadgeVariant, label: string}|null */
    public function of(IpAddress $record): ?array
    {
        if ($record->gateway_checked_at === null) {
            return null;
        }

        $seen = $record->gateway_seen_at?->translatedFormat('d.m.Y H:i');

        return $record->gateway_up
            ? ['up' => true, 'variant' => BadgeVariant::Green, 'label' => "Gateway yanıt veriyor · {$seen}"]
            : ['up' => false, 'variant' => BadgeVariant::Red, 'label' => 'Gateway yanıt vermiyor · '
                .($seen ? "son yanıt {$seen}" : 'hiç yanıt yok')];
    }
}
