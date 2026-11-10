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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * /24 bloğu bölme ve bölmeyi kaldırma (bkz. Ipv4BlockRules, Ipv4SplitLayout).
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737).
 */
class BlockSplitTest extends TestCase
{
    use RefreshDatabase;

    /** Kullanıcının verdiği örnek düzen: /29'lar arasında /28 ve /27, sırası korunur. */
    private const KARISIK = '29x10 28x2 29x6 27 28x2 29x2 28';

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

    /** @return list<string> */
    private function parcalar(): array
    {
        return $this->grup->ipAddresses()->where('prefix', '>', 24)->ordered()->get()
            ->map(fn (IpAddress $a): string => $a->notation)->all();
    }

    #[Test]
    public function karisik_duzen_yazildigi_sirayla_bolunur(): void
    {
        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.split', $this->blok), ['layout' => self::KARISIK])
            ->assertRedirect(route('ip-addresses.plan', ['grup' => $this->grup->uuid, 'blok' => $this->blok->uuid]))
            ->assertSessionHasNoErrors();

        $parcalar = $this->parcalar();
        $this->assertCount(24, $parcalar);
        $this->assertSame(['198.51.100.0/29', '198.51.100.72/29', '198.51.100.80/28', '198.51.100.96/28', '198.51.100.112/29'],
            [$parcalar[0], $parcalar[9], $parcalar[10], $parcalar[11], $parcalar[12]]);
        $this->assertSame(['198.51.100.160/27', '198.51.100.192/28', '198.51.100.240/28'],
            [$parcalar[18], $parcalar[19], $parcalar[23]]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'updated',
            'summary' => 'Blok bölündü: Grup A · 198.51.100.0/24 (musteri-blok) → 24 parça (/29×10 /28×2 /29×6 /27 /28×2 /29×2 /28)',
        ]);
    }

    #[Test]
    public function hizasiz_parca_reddedilir(): void
    {
        $this->actingAs($this->bolen())
            ->post(route('ip-addresses.split', $this->blok), ['layout' => '29 28 25'])
            ->assertSessionHasErrors(['layout' => '/28 parçası 16 adreslik sınırda başlamalı; 198.51.100.8 uygun değil. Parçaların sırasını değiştirin.']);

        $this->assertSame([], $this->parcalar());
    }

    #[Test]
    public function blogu_tam_doldurmayan_ya_da_asan_duzen_reddedilir(): void
    {
        $kullanici = $this->bolen();

        $this->actingAs($kullanici)
            ->post(route('ip-addresses.split', $this->blok), ['layout' => '25'])
            ->assertSessionHasErrors(['layout' => 'Parçalar 128 adres ediyor; blok tam 256 adres olmalı (eksik 128).']);

        $this->actingAs($kullanici)
            ->post(route('ip-addresses.split', $this->blok), ['layout' => '25x3'])
            ->assertSessionHasErrors('layout');

        $this->assertSame([], $this->parcalar());
    }

    #[Test]
    public function bozuk_yazim_ve_aralik_disi_boy_reddedilir(): void
    {
        $kullanici = $this->bolen();

        foreach (['yarim', '29x0', '29x99999', '24x1', '33'] as $duzen) {
            $this->actingAs($kullanici)
                ->post(route('ip-addresses.split', $this->blok), ['layout' => $duzen])
                ->assertSessionHasErrors('layout');
        }

        $this->assertSame([], $this->parcalar());
    }

    /** Bölme tek kattır: bölünmüş blok yeniden, parça da hiç bölünmez. */
    #[Test]
    public function ic_ice_bolme_yok(): void
    {
        $kullanici = $this->bolen();
        $this->actingAs($kullanici)->post(route('ip-addresses.split', $this->blok), ['layout' => '25x2']);
        $parca = $this->grup->ipAddresses()->where('prefix', 25)->firstOrFail();

        $this->actingAs($kullanici)
            ->post(route('ip-addresses.split', $this->blok), ['layout' => '26x4'])
            ->assertSessionHasErrors(['layout' => 'Bu blok zaten bölünmüş; yeniden bölmek için önce bölmesini kaldırın.']);

        $this->actingAs($kullanici)
            ->post(route('ip-addresses.split', $parca), ['layout' => '26x2'])
            ->assertSessionHasErrors(['layout' => 'Yalnız IPv4 /24 blok bölünür.']);

        $this->assertCount(2, $this->parcalar());
    }

    /** Bölme kayıt açar: "düzenle" tek başına yetmez, grupta "ekle" de gerekir. */
    #[Test]
    public function ekleme_yetkisi_olmadan_bolunemez(): void
    {
        $this->actingAs($this->kullanici(['view', 'update']))
            ->post(route('ip-addresses.split', $this->blok), ['layout' => '25x2'])
            ->assertForbidden();

        $this->assertSame([], $this->parcalar());
    }

    #[Test]
    public function baska_grubun_blogu_bolunemez(): void
    {
        $diger = AddressGroup::factory()->create();

        $this->actingAs($this->kullanici(['view', 'update', 'create'], $diger))
            ->post(route('ip-addresses.split', $this->blok), ['layout' => '25x2'])
            ->assertForbidden();

        $this->assertSame([], $this->parcalar());
    }

    #[Test]
    public function bolme_gerekceyle_kaldirilir(): void
    {
        $this->actingAs($this->bolen())->post(route('ip-addresses.split', $this->blok), ['layout' => '26x4']);
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
        $this->actingAs($this->bolen())->post(route('ip-addresses.split', $this->blok), ['layout' => '25x2']);

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
