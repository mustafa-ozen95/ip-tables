<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\IpAddress;
use App\Services\Ipv4BlockPlan;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Blok ağacı: /22 kökler, ikiye bölünen düğümler, kayıtsız yarılar yaprak.
 * /24 ve genişi blok (adres alanı), /25–/32 atama (alandan kullanılan).
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
    private function kok(string ...$girdiler): array
    {
        return (new Ipv4BlockPlan)->build($this->kayitlar(...$girdiler))['roots'][0];
    }

    /**
     * @param  array<string, mixed>  $dugum
     * @return array<string, mixed>
     */
    private function yol(array $dugum, string ...$adimlar): array
    {
        foreach ($adimlar as $adim) {
            $dugum = collect($dugum['children'])->firstWhere('notation', $adim)
                ?? $this->fail("{$adim} düğümü yok");
        }

        return $dugum;
    }

    #[Test]
    public function kayitlar_22_koklerde_toplanir(): void
    {
        $plan = (new Ipv4BlockPlan)->build($this->kayitlar('198.51.100.0/24', '198.51.103.0/24', '203.0.113.0/24'));

        $this->assertSame(['198.51.100.0/22', '203.0.112.0/22'], array_column($plan['roots'], 'notation'));
    }

    #[Test]
    public function blok_disi_alan_kayitsiz_blok_ici_bos(): void
    {
        $kok = $this->kok('198.51.100.0/24');

        $this->assertSame(['198.51.100.0/23', '198.51.102.0/23'], array_column($kok['children'], 'notation'));
        $this->assertSame('unregistered', $kok['children'][1]['state']);
        $this->assertSame('Kayıtsız', $kok['children'][1]['stateLabel']);
        $this->assertSame('unregistered', $this->yol($kok, '198.51.100.0/23', '198.51.101.0/24')['state']);

        $blok = $this->yol($kok, '198.51.100.0/23', '198.51.100.0/24');
        $this->assertSame('free', $blok['state']);
        $this->assertSame('198.51.100.0/24', $blok['records'][0]->name);
        $this->assertSame([], $blok['children']);

        /* Kökün alanı yalnız kayıtlı blok kadardır: 1024 değil 256. */
        $this->assertSame(256, $kok['space']);
        $this->assertSame('Boş', $kok['stateLabel']);
    }

    /** Bloktaki tek atama /32'ye kadar her düzeyde bölünerek iner; kardeş yarılar boştur. */
    #[Test]
    public function atama_32ye_kadar_bolunur(): void
    {
        $blok = $this->yol($this->kok('198.51.100.0/24', '198.51.100.10'), '198.51.100.0/23', '198.51.100.0/24');

        $dugum = $blok;
        foreach (range(25, 32) as $onek) {
            $this->assertCount(2, $dugum['children']);
            $bos = collect($dugum['children'])->firstWhere('used', 0);
            $this->assertSame('free', $bos['state']);
            $dugum = collect($dugum['children'])->firstWhere('used', '>', 0);
            $this->assertSame($onek, $dugum['prefix']);
        }

        $this->assertSame('198.51.100.10/32', $dugum['notation']);
        $this->assertSame('Dolu', $dugum['stateLabel']);
    }

    #[Test]
    public function doluluk_blok_alanina_gore_hesaplanir(): void
    {
        $kok = $this->kok('198.51.100.0/24', '198.51.100.0/25', '198.51.100.128/26');

        $blok = $this->yol($kok, '198.51.100.0/23', '198.51.100.0/24');
        $this->assertSame(192, $blok['used']);
        $this->assertSame('%75 dolu', $blok['stateLabel']);
        $this->assertSame('%75 dolu', $kok['stateLabel']);
    }

    #[Test]
    public function ic_ice_atamalar_iki_kez_sayilmaz(): void
    {
        $blok = $this->yol($this->kok('198.51.100.0/24', '198.51.100.0/25', '198.51.100.8/30'), '198.51.100.0/23', '198.51.100.0/24');

        $this->assertSame(128, $blok['used']);
    }

    #[Test]
    public function hizasiz_blok_ag_adresine_oturur(): void
    {
        $blok = $this->yol($this->kok('198.51.100.5/24'), '198.51.100.0/23', '198.51.100.0/24');

        $this->assertSame('free', $blok['state']);
    }

    #[Test]
    public function genis_blok_listede_durur_icindeki_kok_kayitlidir(): void
    {
        $plan = (new Ipv4BlockPlan)->build($this->kayitlar('198.51.0.0/16', '198.51.100.8/30', '2001:db8::10'));

        $this->assertSame(['198.51.0.0/16'], array_map(fn (IpAddress $r): string => $r->name, $plan['wide']));
        $this->assertSame(1, $plan['skipped']);
        $this->assertSame('198.51.100.0/22', $plan['roots'][0]['notation']);
        $this->assertSame('%1 dolu', $plan['roots'][0]['stateLabel']);
    }
}
