<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\IpAddressPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * /24 bloğu aralık aralık bölme, bölmeyi kaldırma, parçayı birleştirme ve
 * ikiye ayırma (bkz. Ipv4BlockRules, Ipv4SplitLayout).
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737).
 */
class BlockSplitTest extends TestCase
{
    use RefreshDatabase;

    private AddressGroup $grup;

    private IpAddress $blok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grup = AddressGroup::factory()->create(['name' => 'Grup A']);
        $this->blok = IpAddress::factory()->for($this->grup, 'group')->address('198.51.100.0/24')->create(['name' => 'musteri-blok']);
    }

    /** @param  list<string>  $aksiyonlar */
    private function kullanici(array $aksiyonlar, ?AddressGroup $grup = null): User
    {
        $kullanici = User::factory()->create();

        foreach ($aksiyonlar as $aksiyon) {
            UserPermission::create([
                'user_id' => $kullanici->id,
                'resource' => IpAddressPolicy::RESOURCE,
                'resource_id' => ($grup ?? $this->grup)->id,
                'action' => $aksiyon,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return $kullanici;
    }

    private function bolen(): User
    {
        return $this->kullanici(['view', 'update', 'create']);
    }

    /**
     * Satırlar: [subnet, aralık ("64-79"), adet]; adet pencerede subneti de
     * taşır ("28:1"), boş subnette boştur.
     *
     * @param  list<array{0: int|string, 1: string, 2: int|string}>  $satirlar
     * @param  array<string, mixed>  $ek
     */
    private function bol(array $satirlar, ?User $kullanici = null, array $ek = []): TestResponse
    {
        return $this->actingAs($kullanici ?? $this->bolen())->post(route('ip-addresses.split', $this->blok), [
            'ranges' => array_map(fn (array $s): array => [
                'prefix' => $s[0], 'slot' => $s[1], 'count' => $s[0] === '' ? '' : "{$s[0]}:{$s[2]}",
            ], $satirlar),
            ...$ek,
        ]);
    }

    /** @return list<string> */
    private function parcalar(): array
    {
        return $this->grup->ipAddresses()->where('prefix', '>', 24)->ordered()->get()
            ->map(fn (IpAddress $a): string => $a->notation)->all();
    }

    private function parca(string $notasyon): IpAddress
    {
        [$adres, $onek] = explode('/', $notasyon);

        return $this->grup->ipAddresses()->where('address', $adres)->where('prefix', (int) $onek)->firstOrFail();
    }

    /** Her aralık kendi subnetine, tek seferde: .0–.63 /29, .64–.127 /28, .128–.191 /27. */
    #[Test]
    public function araliklar_kendi_subnetine_bolunur_kalani_bos_kalir(): void
    {
        $this->bol([[29, '0-63', 8], [28, '64-127', 4], [27, '128-191', 2], ['', '', '1']])
            ->assertRedirect(route('ip-addresses.index', ['grup' => $this->grup->uuid, 'kayit' => $this->blok->uuid, 'blok' => $this->blok->uuid]))
            ->assertSessionHasNoErrors();

        $parcalar = $this->parcalar();
        $this->assertCount(8 + 4 + 2, $parcalar);
        $this->assertSame(['198.51.100.0/29', '198.51.100.56/29', '198.51.100.64/28', '198.51.100.112/28', '198.51.100.128/27', '198.51.100.160/27'],
            [$parcalar[0], $parcalar[7], $parcalar[8], $parcalar[11], $parcalar[12], $parcalar[13]]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'updated',
            'summary' => 'Blok bölündü: Grup A · 198.51.100.0/24 (musteri-blok) → 14 parça (.0–.63 /29×8 · .64–.127 /28×4 · .128–.191 /27×2)',
        ]);
    }

    /** Boş alan sonra bölünür; bölünmüş yerle çakışan aralık reddedilir (tek kat). */
    #[Test]
    public function bos_alan_sonra_bolunur_dolu_yer_bolunmez(): void
    {
        $this->bol([[26, '0-127', 2]])->assertSessionHasNoErrors();

        $this->bol([[27, '64-191', 4]])
            ->assertSessionHasErrors(['ranges' => '198.51.100.64 – .191 aralığı bölünmüş bir yerle çakışıyor; yalnız boş alan bölünür.']);

        $this->bol([[25, '128-255', 1]])->assertSessionHasNoErrors();

        $this->assertSame(['198.51.100.0/26', '198.51.100.64/26', '198.51.100.128/25'], $this->parcalar());
    }

    #[Test]
    public function hatali_aralik_reddedilir(): void
    {
        $durumlar = [
            '1. satırda subnet ve müsait aralık birlikte seçilir.' => [[29, '', 1]],
            '1. satırdaki aralık 1 tane /28 boyunda değil; listeden yeniden seçin.' => [[28, '0-7', 1]],
            '1. satır bloğun sonunu aşıyor: .240’ten sonra en çok 2 tane /29 sığar.' => [[29, '240-263', 3]],
            '/28 16 adreslik sınırda başlar; 198.51.100.8 uygun değil (ör. .0 ya da .16).' => [[28, '8-23', 1]],
            '198.51.100.0 – .63 ile 198.51.100.32 – .95 aralıkları çakışıyor.' => [[27, '0-63', 2], [27, '32-95', 2]],
            'En az bir satırda subnet ve müsait aralık seçin.' => [['', '', '1']],
        ];

        foreach ($durumlar as $mesaj => $satirlar) {
            $this->bol($satirlar)->assertSessionHasErrors(['ranges' => $mesaj]);
        }

        /* Adet başka subnetin listesinden gelirse aralık boyu ona göre okunmaz. */
        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.split', $this->blok), ['ranges' => [['prefix' => 29, 'slot' => '0-15', 'count' => '28:1']]])
            ->assertSessionHasErrors(['ranges' => '1. satırdaki adet /29 için değil; listeden yeniden seçin.']);
        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.split', $this->blok), ['ranges' => [['prefix' => 29, 'slot' => '0-7', 'count' => '2']]])
            ->assertSessionHasErrors('ranges.0.count');

        /* /31 ve /32 pencereden açılmaz (ikiye ayırmayla açılır); /24 parça olmaz. */
        foreach ([31, 24] as $subnet) {
            $this->bol([[$subnet, '0-1', 1]])->assertSessionHasErrors('ranges.0.prefix');
        }

        $this->assertSame([], $this->parcalar());
    }

    /** Önizleme kayıt açmaz; pencere aynı satırlarla, sonuç özetiyle döner. */
    #[Test]
    public function onizleme_kayit_acmaz(): void
    {
        $this->bol([[29, '0-63', 8]], ek: ['onizle' => '1'])
            ->assertRedirect(route('ip-addresses.index', ['grup' => $this->grup->uuid, 'kayit' => $this->blok->uuid, 'pencere' => 'bol']))
            ->assertSessionHas('splitPreview', '8 parça: .0–.63 /29×8')
            ->assertSessionHasInput('ranges.0.slot', '0-63');

        $this->assertSame([], $this->parcalar());
    }

    /** Bölme kayıt açar: "düzenle" tek başına yetmez, grupta "ekle" de gerekir. */
    #[Test]
    public function ekleme_yetkisi_olmadan_bolunemez(): void
    {
        $this->bol([[25, '0-255', 2]], $this->kullanici(['view', 'update']))->assertForbidden();

        $this->assertSame([], $this->parcalar());
    }

    #[Test]
    public function baska_grubun_blogu_bolunemez(): void
    {
        $this->bol([[25, '0-255', 2]], $this->kullanici(['view', 'update', 'create'], AddressGroup::factory()->create()))
            ->assertForbidden();

        $this->assertSame([], $this->parcalar());
    }

    #[Test]
    public function parca_bolme_penceresiyle_bolunmez(): void
    {
        $this->bol([[25, '0-255', 2]])->assertSessionHasNoErrors();
        $parca = $this->parca('198.51.100.0/25');

        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.split', $parca), ['ranges' => [['prefix' => 26, 'slot' => '0-63', 'count' => '26:1']]])
            ->assertSessionHasErrors(['ranges' => 'Yalnız IPv4 /24 blok bölünür.']);

        $this->assertCount(2, $this->parcalar());
    }

    /** Sıralı iki /29 bir /28 olur; adı olan parça kalır. */
    #[Test]
    public function sirali_iki_esi_birlesir(): void
    {
        $this->bol([[29, '0-255', 32]])->assertSessionHasNoErrors();
        $this->parca('198.51.100.8/29')->update(['name' => 'musteri-x', 'description' => 'hat 1']);
        $kalan = $this->parca('198.51.100.8/29');

        $this->actingAs($this->kullanici(['view', 'update', 'delete']))
            ->post(route('ip-addresses.merge', $this->parca('198.51.100.0/29')))
            ->assertRedirect(route('ip-addresses.index', ['grup' => $this->grup->uuid, 'kayit' => $kalan->uuid, 'blok' => $this->blok->uuid]))
            ->assertSessionHas('status');

        $this->assertSame(['198.51.100.0/28', '198.51.100.16/29'], array_slice($this->parcalar(), 0, 2));
        $this->assertCount(31, $this->parcalar());
        $this->assertSame(['198.51.100.0', 28, 'musteri-x', 'hat 1'], [$kalan->fresh()->address, $kalan->fresh()->prefix, $kalan->fresh()->name, $kalan->fresh()->description]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'updated', 'summary' => 'Parçalar birleştirildi: Grup A · 198.51.100.0/29 + 198.51.100.8/29 → 198.51.100.0/28']);
    }

    #[Test]
    public function birlesme_kurali(): void
    {
        $this->bol([[29, '0-7', 1], [29, '16-31', 2], [28, '32-47', 1], [25, '128-255', 1]])->assertSessionHasNoErrors();
        $this->parca('198.51.100.16/29')->update(['name' => 'a']);
        $this->parca('198.51.100.24/29')->update(['name' => 'b']);
        $kullanici = $this->kullanici(['view', 'update', 'delete']);

        $durumlar = [
            '198.51.100.0/29' => 'Birleşecek eş parça yok: 198.51.100.8/29 bölünmüş ve aynı boyda olmalı.',
            '198.51.100.32/28' => 'Birleşecek eş parça yok: 198.51.100.48/28 bölünmüş ve aynı boyda olmalı.',
            '198.51.100.16/29' => 'İki parçanın da adı var; birleştirmek için birini boşaltın.',
            '198.51.100.128/25' => '/25 parçalar birleşmez; bloğun tamamı için bölmeyi kaldırın.',
        ];

        foreach ($durumlar as $parca => $mesaj) {
            $this->actingAs($kullanici)
                ->from(route('ip-addresses.index'))
                ->post(route('ip-addresses.merge', $this->parca($parca)))
                ->assertSessionHas('error', $mesaj);
        }

        $this->actingAs($kullanici)
            ->post(route('ip-addresses.merge', $this->blok))
            ->assertSessionHas('error', 'Yalnız bir bloğun parçası birleştirilir.');

        $this->assertCount(5, $this->parcalar());
    }

    /** Birleştirme eşi siler: silme yetkisi de aranır. */
    #[Test]
    public function silme_yetkisi_olmadan_birlesmez(): void
    {
        $this->bol([[25, '0-255', 2]])->assertSessionHasNoErrors();

        $this->actingAs($this->kullanici(['view', 'update']))
            ->post(route('ip-addresses.merge', $this->parca('198.51.100.0/25')))
            ->assertForbidden();

        $this->assertCount(2, $this->parcalar());
    }

    /** /28 dört sıralı /30 olur: kayıt ilk parçadır, adını korur; ping sonucu silinir. */
    #[Test]
    public function parca_secilen_boyda_parcalanir(): void
    {
        $this->bol([[28, '0-255', 16]])->assertSessionHasNoErrors();
        $parca = $this->parca('198.51.100.16/28');
        $parca->forceFill(['name' => 'musteri-y', 'gateway_up' => true, 'gateway_checked_at' => now()])->save();

        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.subdivide', $parca), ['prefix' => 30])
            ->assertRedirect(route('ip-addresses.index', ['grup' => $this->grup->uuid, 'kayit' => $parca->uuid, 'blok' => $this->blok->uuid]))
            ->assertSessionHas('status', 'Parça 4 × /30 olarak bölündü.');

        $this->assertSame(['198.51.100.0/28', '198.51.100.16/30', '198.51.100.20/30', '198.51.100.24/30', '198.51.100.28/30', '198.51.100.32/28'],
            array_slice($this->parcalar(), 0, 6));
        $parca->refresh();
        $this->assertSame(['198.51.100.16', 30, 'musteri-y', null], [$parca->address, $parca->prefix, $parca->name, $parca->gateway_up]);
        $this->assertNull($this->parca('198.51.100.28/30')->name);
        $this->assertDatabaseHas('activity_logs', ['summary' => 'Parça bölündü: Grup A · 198.51.100.16/28 (musteri-y) → 4 × /30 (198.51.100.16/30 … 198.51.100.28/30)']);
    }

    #[Test]
    public function parcalama_kurali_ve_yetkisi(): void
    {
        $this->bol([[28, '0-255', 16]])->assertSessionHasNoErrors();

        /* /28 → 16 × /32: pencerenin açmadığı boylar burada açılır. */
        $this->actingAs($this->bolen())->post(route('ip-addresses.subdivide', $this->parca('198.51.100.0/28')), ['prefix' => 32]);
        $this->assertSame(['198.51.100.0', '198.51.100.1', '198.51.100.15', '198.51.100.16/28'],
            [...array_slice($this->parcalar(), 0, 2), ...array_slice($this->parcalar(), 15, 2)]);

        foreach ([[28, '/28 parça /29 ile /32 arasında bir boya bölünür; /28 olmaz.'], [33, '/28 parça /29 ile /32 arasında bir boya bölünür; /33 olmaz.']] as [$boy, $hata]) {
            $this->actingAs($this->bolen())
                ->post(route('ip-addresses.subdivide', $this->parca('198.51.100.16/28')), ['prefix' => $boy])
                ->assertSessionHas('error', $hata);
        }

        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.subdivide', $this->parca('198.51.100.16/28')))
            ->assertSessionHas('error');

        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.subdivide', $this->parca('198.51.100.0/32')), ['prefix' => 32])
            ->assertSessionHas('error', '/32 tek adrestir, parçalanmaz.');

        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.subdivide', $this->blok), ['prefix' => 25])
            ->assertSessionHas('error', 'Yalnız bir bloğun parçası parçalanır.');

        /* Öteki parçalar yeni kayıttır: "ekle" yetkisi olmadan parçalanmaz. */
        $this->actingAs($this->kullanici(['view', 'update']))
            ->post(route('ip-addresses.subdivide', $this->parca('198.51.100.16/28')), ['prefix' => 29])
            ->assertForbidden();

        $this->assertCount(31, $this->parcalar());
    }

    #[Test]
    public function bolme_gerekceyle_kaldirilir(): void
    {
        $this->bol([[26, '0-255', 4]])->assertSessionHasNoErrors();
        $silen = $this->kullanici(['view', 'delete']);

        $this->actingAs($silen)
            ->delete(route('ip-addresses.unsplit', $this->blok))
            ->assertSessionHasErrors('reason');
        $this->assertCount(4, $this->parcalar());

        $this->actingAs($silen)
            ->delete(route('ip-addresses.unsplit', $this->blok), ['reason' => 'Yeniden planlanacak'])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->parcalar());
        $this->assertModelExists($this->blok);
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted', 'reason' => 'Yeniden planlanacak']);
    }

    #[Test]
    public function silme_yetkisi_olmadan_bolme_kaldirilamaz(): void
    {
        $this->bol([[25, '0-255', 2]])->assertSessionHasNoErrors();

        $this->actingAs($this->kullanici(['view', 'update', 'create']))
            ->delete(route('ip-addresses.unsplit', $this->blok), ['reason' => 'deneme'])
            ->assertForbidden();

        $this->assertCount(2, $this->parcalar());
    }

    #[Test]
    public function bolunmemis_blogun_bolmesi_kaldirilamaz(): void
    {
        $this->actingAs($this->kullanici(['view', 'delete']))
            ->delete(route('ip-addresses.unsplit', $this->blok), ['reason' => 'deneme'])
            ->assertSessionHas('error', 'Bu blok bölünmemiş.');
    }
}
