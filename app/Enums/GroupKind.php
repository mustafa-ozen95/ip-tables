<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Grubun türü: gruba hangi adreslerin girebileceği.
 *
 * Dört yerleşik grup (IPv4, IPv6, Local IPv4, Local IPv6) kurulumla gelir;
 * "Karışık" her adresi kabul eder. Adresin türünü IpAddressParser belirler,
 * kullanıcı seçmez: yerel bir adres "IPv4" grubuna düşemez.
 *
 * Değerler veritabanında saklanır; değiştirmek eski kayıtları bozar.
 */
enum GroupKind: string
{
    case Ipv4 = 'ipv4';
    case Ipv6 = 'ipv6';
    case LocalIpv4 = 'local-ipv4';
    case LocalIpv6 = 'local-ipv6';
    case Mixed = 'mixed';

    /** Adresin kendi türü (Karışık hiçbir adresin türü değildir). */
    public static function forAddress(bool $ipv6, bool $local): self
    {
        return match (true) {
            $ipv6 && $local => self::LocalIpv6,
            $ipv6 => self::Ipv6,
            $local => self::LocalIpv4,
            default => self::Ipv4,
        };
    }

    /** @return array<int, self> Kurulumla gelen gruplar, menüdeki sırayla. */
    public static function builtIn(): array
    {
        return [self::Ipv4, self::Ipv6, self::LocalIpv4, self::LocalIpv6];
    }

    public function accepts(self $addressKind): bool
    {
        return $this === self::Mixed || $this === $addressKind;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ipv4 => 'IPv4',
            self::Ipv6 => 'IPv6',
            self::LocalIpv4 => 'Local IPv4',
            self::LocalIpv6 => 'Local IPv6',
            self::Mixed => 'Karışık',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Ipv4 => 'Genel (internete açık) IPv4 adresleri ve blokları.',
            self::Ipv6 => 'Genel (internete açık) IPv6 adresleri ve blokları.',
            self::LocalIpv4 => 'Özel ağ IPv4 adresleri: 10/8, 172.16/12, 192.168/16 ve benzerleri.',
            self::LocalIpv6 => 'Özel ağ IPv6 adresleri: ULA (fc00::/7), bağlantı-yerel (fe80::/10).',
            self::Mixed => 'Her türden adres.',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Ipv4 => BadgeVariant::Blue,
            self::Ipv6 => BadgeVariant::Purple,
            self::LocalIpv4 => BadgeVariant::Green,
            self::LocalIpv6 => BadgeVariant::Cyan,
            self::Mixed => BadgeVariant::Gray,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])
            ->all();
    }
}
