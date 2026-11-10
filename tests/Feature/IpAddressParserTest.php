<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
use App\Services\IpAddressParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Girilen metnin adrese çevrilmesi ve türünün belirlenmesi.
 *
 * Tür, gruba hangi adresin girebileceğinin tek kaynağıdır; yanlış
 * sınıflanan bir adres yanlış listeye düşer.
 */
class IpAddressParserTest extends TestCase
{
    /** @return array<string, array{string, GroupKind}> */
    public static function turler(): array
    {
        return [
            'genel IPv4' => ['192.0.2.10', GroupKind::Ipv4],
            'genel IPv4 blok' => ['198.51.100.0/24', GroupKind::Ipv4],
            'RFC 1918' => ['10.20.0.1', GroupKind::LocalIpv4],
            '172.16/12' => ['172.31.255.1', GroupKind::LocalIpv4],
            '172.16/12 dışı' => ['172.32.0.1', GroupKind::Ipv4],
            'CGNAT' => ['100.64.1.1', GroupKind::LocalIpv4],
            'döngü' => ['127.0.0.1', GroupKind::LocalIpv4],
            'genel IPv6' => ['2001:db8::10', GroupKind::Ipv6],
            'ULA' => ['fd00:10::1', GroupKind::LocalIpv6],
            'bağlantı-yerel' => ['fe80::1', GroupKind::LocalIpv6],
            'IPv6 döngü' => ['::1', GroupKind::LocalIpv6],
        ];
    }

    #[Test]
    #[DataProvider('turler')]
    public function tur_dogru_belirlenir(string $girdi, GroupKind $beklenen): void
    {
        $this->assertSame($beklenen, app(IpAddressParser::class)->parse($girdi)?->kind);
    }

    /** "2001:DB8:0::1" ile "2001:db8::1" aynı kayıttır; ikincisi çift kayıt sayılmalı. */
    #[Test]
    public function adres_kanonik_bicimde_saklanir(): void
    {
        $parsed = app(IpAddressParser::class)->parse(' 2001:DB8:0:0::1/64 ');

        $this->assertSame('2001:db8::1', $parsed->address);
        $this->assertSame(64, $parsed->prefix);
        $this->assertSame('2001:db8::1/64', $parsed->notation());
    }

    #[Test]
    public function tek_adres_tam_onekle_saklanir_oneksiz_gosterilir(): void
    {
        $v4 = app(IpAddressParser::class)->parse('192.0.2.10');
        $v6 = app(IpAddressParser::class)->parse('2001:db8::10');

        $this->assertSame([32, '192.0.2.10'], [$v4->prefix, $v4->notation()]);
        $this->assertSame([128, '2001:db8::10'], [$v6->prefix, $v6->notation()]);
    }

    /** @return array<string, array{string}> */
    public static function gecersizler(): array
    {
        return [
            'boş' => [''],
            'metin' => ['sunucu'],
            'aralık dışı oktet' => ['192.0.2.256'],
            'IPv4 öneki 32 üstü' => ['192.0.2.0/33'],
            'IPv6 öneki 128 üstü' => ['2001:db8::/129'],
            'eksi önek' => ['192.0.2.0/-1'],
            'boş önek' => ['192.0.2.0/'],
            'harfli önek' => ['192.0.2.0/2a'],
            'iki önek' => ['192.0.2.0/24/8'],
        ];
    }

    #[Test]
    #[DataProvider('gecersizler')]
    public function gecersiz_girdi_reddedilir(string $girdi): void
    {
        $this->assertNull(app(IpAddressParser::class)->parse($girdi));
    }

    /** Yerel aralıklar ayardan gelir; liste değişince sınıflama da değişir. */
    #[Test]
    public function yerel_araliklar_ayardan_okunur(): void
    {
        config(['ip.local_ranges' => ['192.0.2.0/24']]);

        $this->assertSame(GroupKind::LocalIpv4, app(IpAddressParser::class)->parse('192.0.2.10')->kind);
        $this->assertSame(GroupKind::Ipv4, app(IpAddressParser::class)->parse('10.0.0.1')->kind);
    }

    /** Karışık grup her türü, diğerleri yalnız kendi türünü kabul eder. */
    #[Test]
    public function grup_turu_yalniz_kendi_adresini_kabul_eder(): void
    {
        $this->assertTrue(GroupKind::Mixed->accepts(GroupKind::LocalIpv6));
        $this->assertTrue(GroupKind::Ipv4->accepts(GroupKind::Ipv4));
        $this->assertFalse(GroupKind::Ipv4->accepts(GroupKind::LocalIpv4));
        $this->assertFalse(GroupKind::LocalIpv6->accepts(GroupKind::Ipv6));
    }
}
