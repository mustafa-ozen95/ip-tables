<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
use App\Enums\PermissionEffect;
use App\Models\ActivityLog;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\IpAddressPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listede çift tıklayarak ad / açıklama düzenleme (bkz. inline-edit.js).
 *
 * Aynı alanı düzenleme penceresi de yazar; iki yol aynı yetkiyi, aynı
 * kuralı ister ve kayda "eski → yeni" düşer.
 */
class InlineEditTest extends TestCase
{
    use RefreshDatabase;

    private AddressGroup $grup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grup = AddressGroup::query()->where('kind', GroupKind::Ipv4)->firstOrFail();
    }

    /** @param  list<string>  $aksiyonlar */
    private function kullanici(array $aksiyonlar): User
    {
        $kullanici = User::factory()->create();

        foreach ($aksiyonlar as $yetki) {
            UserPermission::create([
                'user_id' => $kullanici->id,
                'resource' => IpAddressPolicy::RESOURCE,
                'resource_id' => $this->grup->id,
                'action' => $yetki,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return $kullanici;
    }

    private function parca(): IpAddress
    {
        IpAddress::factory()->for($this->grup, 'group')->address('192.0.2.0/24')->create();

        return IpAddress::factory()->for($this->grup, 'group')->address('192.0.2.0/29')
            ->create(['name' => 'ESKI AD', 'description' => null]);
    }

    private function sonKayit(): string
    {
        return ActivityLog::query()->latest('id')->value('summary');
    }

    #[Test]
    public function ad_ve_aciklama_yerinde_degisir_kayda_eski_ve_yeni_duser(): void
    {
        $parca = $this->parca();
        $kullanici = $this->kullanici(['view', 'update']);
        $liste = route('ip-addresses.index', ['grup' => $this->grup->uuid]);

        $this->actingAs($kullanici)->from($liste)
            ->patch(route('ip-addresses.field', $parca), ['field' => 'name', 'value' => '  YENI AD '])
            ->assertRedirect($liste)
            ->assertSessionHas('status', '192.0.2.0/29 adı güncellendi.');

        $this->assertSame('YENI AD', $parca->fresh()->name);
        $this->assertStringContainsString('192.0.2.0/29', $this->sonKayit());
        $this->assertStringContainsString('ad: “ESKI AD” → “YENI AD”', $this->sonKayit());

        $this->actingAs($kullanici)->from($liste)
            ->patch(route('ip-addresses.field', $parca), ['field' => 'description', 'value' => 'K13 ROUTE'])
            ->assertRedirect($liste);

        $this->assertSame('K13 ROUTE', $parca->fresh()->description);
        $this->assertStringContainsString('açıklama: (boş) → “K13 ROUTE”', $this->sonKayit());

        /* Boş bırakılan alan silinir. */
        $this->actingAs($kullanici)->from($liste)
            ->patch(route('ip-addresses.field', $parca), ['field' => 'name', 'value' => ''])
            ->assertRedirect($liste);

        $this->assertNull($parca->fresh()->name);
        $this->assertStringContainsString('ad: “YENI AD” → (boş)', $this->sonKayit());
    }

    #[Test]
    public function yalniz_ad_ve_aciklama_pencereyle_ayni_kuralla_yazilir(): void
    {
        $parca = $this->parca();
        $kullanici = $this->kullanici(['view', 'update']);

        $this->actingAs($kullanici)
            ->patch(route('ip-addresses.field', $parca), ['field' => 'address', 'value' => '203.0.113.0'])
            ->assertSessionHasErrors('field');

        $this->actingAs($kullanici)
            ->patch(route('ip-addresses.field', $parca), ['field' => 'name', 'value' => str_repeat('a', 121)])
            ->assertSessionHasErrors('value');

        $this->assertSame(['192.0.2.0', 'ESKI AD'], [$parca->fresh()->address, $parca->fresh()->name]);
    }

    #[Test]
    public function yalniz_goren_duzenleyemez_alan_da_cizilmez(): void
    {
        $parca = $this->parca();
        $gorur = $this->kullanici(['view']);

        $this->actingAs($gorur)
            ->patch(route('ip-addresses.field', $parca), ['field' => 'name', 'value' => 'ELE GECIRILDI'])
            ->assertForbidden();
        $this->assertSame('ESKI AD', $parca->fresh()->name);

        $this->actingAs($gorur)->get(route('ip-addresses.index', ['grup' => $this->grup->uuid]))
            ->assertOk()
            ->assertSee('ESKI AD')
            ->assertDontSee('data-inline-edit', false);

        $this->actingAs($this->kullanici(['view', 'update']))->get(route('ip-addresses.index', ['grup' => $this->grup->uuid]))
            ->assertSee('data-inline-edit="name"', false)
            ->assertSee('data-inline-url="'.route('ip-addresses.field', $parca).'"', false);
    }

    /**
     * NETWORK ROLÜ YALNIZ AD VE AÇIKLAMA DEĞİŞTİRİR: "Ad ve açıklama düzenle"
     * yerinde düzenlemeyi açar; adres, grup, vurgu (pencere), bölme, silme,
     * ekleme ve ping "Düzenle"/"Sil"/"Ekle"/"Ping at" ister.
     */
    #[Test]
    public function ad_aciklama_yetkisi_baska_islem_acmaz(): void
    {
        $parca = $this->parca();
        $blok = IpAddress::query()->where('prefix', 24)->firstOrFail();
        $kullanici = $this->kullanici(['view', 'update_details']);

        $this->actingAs($kullanici)
            ->patch(route('ip-addresses.field', $parca), ['field' => 'name', 'value' => 'YENI AD'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('YENI AD', $parca->fresh()->name);

        $this->actingAs($kullanici)->get(route('ip-addresses.index', ['grup' => $this->grup->uuid]))
            ->assertSee('data-inline-edit="name"', false);

        $this->actingAs($kullanici)->put(route('ip-addresses.update', $parca), [
            'group' => $this->grup->uuid, 'address' => '192.0.2.8/29', 'name' => 'X', 'highlight' => 'red',
        ])->assertForbidden();
        $this->actingAs($kullanici)->delete(route('ip-addresses.destroy', $parca))->assertForbidden();
        $this->actingAs($kullanici)->post(route('ip-addresses.split', $blok), ['prefix' => 29])->assertForbidden();
        $this->actingAs($kullanici)->post(route('ip-addresses.ping', $parca))->assertForbidden();
        $this->actingAs($kullanici)->post(route('ip-addresses.store'), [
            'group' => $this->grup->uuid, 'address' => '198.51.100.0/24',
        ])->assertForbidden();

        $this->assertSame(['192.0.2.0', 29, 'YENI AD'], [$parca->fresh()->address, $parca->fresh()->prefix, $parca->fresh()->name]);
        $this->assertSame(2, IpAddress::query()->count());
    }

    /** Yerleşik roller: Network yalnız görür ve ad/açıklama düzenler; diğerleri yalnız görür. */
    #[Test]
    public function yerlesik_rol_sablonlari(): void
    {
        $this->assertSame(['view', 'update_details'], config('permissions.templates.network.address_group.all'));
        $this->assertSame(['view'], config('permissions.templates.teknik.address_group.all'));
        $this->assertSame(['view'], config('permissions.templates.muhasebe.address_group.all'));
    }

    /** Bölünmemiş /24 satırı da düzenlenir; bölünmüş bloğun başlığı katlanır, düzenlenmez. */
    #[Test]
    public function bolunmemis_blok_satiri_da_duzenlenir(): void
    {
        $parca = $this->parca();
        $tek = IpAddress::factory()->for($this->grup, 'group')->address('198.51.100.0/24')->create(['name' => 'TEK BLOK']);
        $bolunmus = IpAddress::query()->where('address', '192.0.2.0')->where('prefix', 24)->firstOrFail();

        $this->actingAs($this->kullanici(['view', 'update']))->get(route('ip-addresses.index', ['grup' => $this->grup->uuid]))
            ->assertSee('data-inline-url="'.route('ip-addresses.field', $tek).'"', false)
            ->assertSee('data-inline-url="'.route('ip-addresses.field', $parca).'"', false)
            ->assertDontSee('data-inline-url="'.route('ip-addresses.field', $bolunmus).'"', false);
    }

    #[Test]
    public function oturumsuz_istek_girise_doner(): void
    {
        $this->patch(route('ip-addresses.field', $this->parca()), ['field' => 'name', 'value' => 'x'])
            ->assertRedirect(route('login'));
    }

    /** Düzenleme penceresi de kayda neyin neye döndüğünü yazar. */
    #[Test]
    public function pencereyle_duzenleme_de_farki_yazar(): void
    {
        $parca = $this->parca();

        $this->actingAs($this->kullanici(['view', 'create', 'update']))
            ->put(route('ip-addresses.update', $parca), [
                'group' => $this->grup->uuid,
                'address' => '192.0.2.0/29',
                'name' => 'ESKI AD',
                'description' => 'yeni not',
                'highlight' => 'red',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('açıklama: (boş) → “yeni not” · vurgu: (boş) → “Kırmızı”', $this->sonKayit());
        $this->assertStringNotContainsString('ad:', $this->sonKayit());
    }
}
