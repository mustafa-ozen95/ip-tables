<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\IpAddress;
use App\Services\Ipv4BlockPlan;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Blok planı: /24 bloklar, altlarında tek kat bölme parçaları; bloğa
 * oturmayan kayıtlar ayrı listede.
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849).
 */
class Ipv4BlockPlanTest extends TestCase
{
    /** @return Collection<int, IpAddress> */
    private function kayitlar(string ...$girdiler): Collection
    {
        return collect($girdiler)->map(function (string $girdi): IpAddress {
            [$adres, $onek] = array_pad(explode('/', $girdi), 2, null);

            return (new IpAddress)->forceFill([
                'address' => $adres,
                'prefix' => (int) ($onek ?? (str_contains($adres, ':') ? 128 : 32)),
                'name' => $girdi,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function plan(string ...$girdiler): array
    {
        return (new Ipv4BlockPlan)->build($this->kayitlar(...$girdiler));
    }

    #[Test]
    public function yalniz_24_bloklar_adres_sirasiyla_listelenir(): void
    {
        $plan = $this->plan('198.51.100.0/24', '203.0.113.0/24');

        $this->assertSame(['198.51.100.0/24', '203.0.113.0/24'], array_column($plan['blocks'], 'notation'));
        /* Bölünmemiş blokta parça yazısı yok; açıklama sütununda yalnız açıklama. */
        $this->assertSame([null, null], array_column($plan['blocks'], 'partsLabel'));
        /* Bölünmemiş blok tek kayıttır: adı varsa dolu, yoksa boş. */
        $this->assertSame('Dolu', $plan['blocks'][0]['stateLabel']);
        $adsiz = $this->kayitlar('198.51.100.0/24')->each(fn (IpAddress $k) => $k->name = null);
        $this->assertSame('Boş', (new Ipv4BlockPlan)->build($adsiz)['blocks'][0]['stateLabel']);
    }

    /** /29: ilk kullanılabilir adres ağ geçidi, müşteriye .2'den son kullanılabilire kadar. */
    #[Test]
    public function parca_satiri_gecit_ilk_son_ve_maske_tasir(): void
    {
        $satirlar = $this->plan('192.0.2.0/24', '192.0.2.8/29', '192.0.2.16/31', '192.0.2.18/32')['blocks'][0]['parts'];
        $parca = array_values(array_filter($satirlar, fn (array $s): bool => ! $s['free']));

        $this->assertSame(
            ['192.0.2.8/29', '192.0.2.9', '192.0.2.10', '192.0.2.14', '255.255.255.248'],
            [$parca[0]['notation'], $parca[0]['gateway'], $parca[0]['first'], $parca[0]['last'], $parca[0]['mask']],
        );

        /* /31 ve /32'de geçit ayrılmaz. */
        $this->assertSame([null, '192.0.2.16', '192.0.2.17'], [$parca[1]['gateway'], $parca[1]['first'], $parca[1]['last']]);
        $this->assertSame([null, '192.0.2.18', '192.0.2.18'], [$parca[2]['gateway'], $parca[2]['first'], $parca[2]['last']]);
    }

    /** Doluluk adı verilmiş parçalardır; adsız parça boştur. */
    #[Test]
    public function doluluk_adli_parcalara_gore_hesaplanir(): void
    {
        $kayitlar = $this->kayitlar('192.0.2.0/24', '192.0.2.0/25', '192.0.2.128/26', '192.0.2.192/26');
        $kayitlar[3]->name = null;

        $blok = (new Ipv4BlockPlan)->build($kayitlar)['blocks'][0];

        $this->assertSame('3 parça', $blok['partsLabel']);
        $this->assertSame('3 parça', $blok['note']);
        $this->assertSame('%75 dolu', $blok['stateLabel']);
        $this->assertSame(['Dolu', 'Dolu', 'Boş'], array_column($blok['parts'], 'stateLabel'));
    }

    #[Test]
    public function hizasiz_blok_ag_adresine_oturur(): void
    {
        $this->assertSame('192.0.2.0/24', $this->plan('192.0.2.5/24', '192.0.2.8/29')['blocks'][0]['notation']);
        $this->assertSame(
            ['192.0.2.0 – .7', '192.0.2.8/29', '192.0.2.16 – .255'],
            array_column($this->plan('192.0.2.5/24', '192.0.2.8/29')['blocks'][0]['parts'], 'notation'),
        );
    }

    /** Kısmen bölünmüş blokta bölünmemiş alan, parçaların arasında sırasıyla satırdır. */
    #[Test]
    public function bolunmemis_alan_satir_olarak_durur(): void
    {
        $blok = $this->plan('192.0.2.0/24', '192.0.2.0/26', '192.0.2.128/26')['blocks'][0];

        $this->assertSame(['192.0.2.0/26', '192.0.2.64 – .127', '192.0.2.128/26', '192.0.2.192 – .255'], array_column($blok['parts'], 'notation'));
        $this->assertSame([false, true, false, true], array_column($blok['parts'], 'free'));
        $this->assertSame(['192.0.2.64', '192.0.2.127', 'Bölünmemiş'], [$blok['parts'][1]['first'], $blok['parts'][1]['last'], $blok['parts'][1]['stateLabel']]);
        $this->assertSame('2 parça · 128 adres bölünmemiş', $blok['partsLabel']);
    }

    #[Test]
    public function tam_bolunmus_blokta_bos_alan_satiri_yok(): void
    {
        $blok = $this->plan('192.0.2.0/24', '192.0.2.0/25', '192.0.2.128/25')['blocks'][0];

        $this->assertSame([false, false], array_column($blok['parts'], 'free'));
        $this->assertSame('2 parça', $blok['partsLabel']);
    }

    /** Başka gruptaki /24 parçayı sahiplenmez. */
    #[Test]
    public function parca_yalniz_kendi_grubunun_bloguna_baglanir(): void
    {
        $kayitlar = $this->kayitlar('192.0.2.0/24', '192.0.2.8/29');
        $kayitlar[0]->address_group_id = 1;
        $kayitlar[1]->address_group_id = 2;

        $plan = (new Ipv4BlockPlan)->build($kayitlar);

        $this->assertSame([], $plan['blocks'][0]['parts']);
        $this->assertSame(['192.0.2.8/29'], array_map(fn (IpAddress $r): string => $r->name, $plan['others']));
    }

    /** Bloğa girmeyen her kayıt düz satırdır, geldiği sırayla: geniş blok, bloksuz /32, IPv6. */
    #[Test]
    public function bloga_girmeyenler_duz_satir(): void
    {
        $plan = $this->plan('198.51.0.0/16', '203.0.113.5', '2001:db8::10');

        $this->assertSame([], $plan['blocks']);
        $this->assertSame(['198.51.0.0/16', '203.0.113.5', '2001:db8::10'], array_map(fn (IpAddress $r): string => $r->name, $plan['others']));
    }

    #[Test]
    public function istenen_blok_acik_gelir(): void
    {
        $kayitlar = $this->kayitlar('192.0.2.0/24', '198.51.100.0/24');
        $kayitlar[1]->uuid = 'acik-blok';

        $plan = (new Ipv4BlockPlan)->build($kayitlar, 'acik-blok');

        $this->assertSame([false, true], array_column($plan['blocks'], 'open'));
    }

    #[Test]
    public function secili_parcanin_blogu_acik_gelir(): void
    {
        $kayitlar = $this->kayitlar('192.0.2.0/24', '192.0.2.0/25', '198.51.100.0/24');
        $kayitlar[1]->uuid = 'secili-parca';

        $plan = (new Ipv4BlockPlan)->build($kayitlar, null, 'secili-parca');

        $this->assertSame([true, false], array_column($plan['blocks'], 'open'));
    }
}
