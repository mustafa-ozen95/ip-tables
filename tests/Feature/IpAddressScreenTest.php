<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
use App\Enums\PermissionEffect;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\SubGroup;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\IpAddressPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adres ekranı: kim neyi görür, kim neyi yazar.
 *
 * Grup yetki sınırıdır. A grubunda yetkisi olan biri B'nin adresini
 * göremez, değiştiremez, silemez ve kendi adresini B'ye taşıyamaz.
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849).
 */
class IpAddressScreenTest extends TestCase
{
    use RefreshDatabase;

    private function grup(string $ad, GroupKind $tur = GroupKind::Mixed): AddressGroup
    {
        return AddressGroup::factory()->create(['name' => $ad, 'kind' => $tur]);
    }

    private function adres(AddressGroup $grup, string $girdi, ?string $ad = null): IpAddress
    {
        return IpAddress::factory()->for($grup, 'group')->address($girdi)->create(['name' => $ad]);
    }

    /** @param  list<string>  $aksiyonlar */
    private function kullanici(AddressGroup $grup, array $aksiyonlar, ?User $kullanici = null): User
    {
        $kullanici ??= User::factory()->create();

        foreach ($aksiyonlar as $aksiyon) {
            UserPermission::create([
                'user_id' => $kullanici->id,
                'resource' => IpAddressPolicy::RESOURCE,
                'resource_id' => $grup->id,
                'action' => $aksiyon,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return $kullanici;
    }

    /** @param  array<string, mixed>  $ek */
    private function form(AddressGroup $grup, string $adres, array $ek = []): array
    {
        return ['group' => $grup->uuid, 'address' => $adres, 'name' => 'web-01', ...$ek];
    }

    /* ── Yerleşik gruplar ── */

    #[Test]
    public function dort_liste_grubu_kurulumla_gelir(): void
    {
        $this->assertSame(
            ['ipv4', 'ipv6', 'local-ipv4', 'local-ipv6'],
            AddressGroup::query()->orderBy('position')->get()->map(fn (AddressGroup $g): string => $g->kind->value)->all(),
        );
    }

    /* ── Görme ── */

    #[Test]
    public function grubu_olmayan_listeye_giremez(): void
    {
        $this->actingAs(User::factory()->create())->get(route('ip-addresses.index'))->assertForbidden();
    }

    #[Test]
    public function yetkisiz_grubun_adresi_listede_gorunmez(): void
    {
        $acik = $this->grup('Grup A');
        $kapali = $this->grup('Grup B');
        $this->adres($acik, '192.0.2.10', 'gorunen-sunucu');
        $this->adres($kapali, '203.0.113.5', 'gizli-sunucu');

        $this->actingAs($this->kullanici($acik, ['view']))
            ->get(route('ip-addresses.index'))
            ->assertOk()
            ->assertSee('gorunen-sunucu')
            ->assertDontSee('gizli-sunucu')
            ->assertDontSee('203.0.113.5')
            ->assertDontSee('Grup B');
    }

    /** Ayrıntı parametresine başka grubun uuid'i yazmak onu açmaz. */
    #[Test]
    public function baska_grubun_adresi_ayrintida_acilmaz(): void
    {
        $acik = $this->grup('Grup A');
        $gizli = $this->adres($this->grup('Grup B'), '203.0.113.5', 'gizli-sunucu');

        $this->actingAs($this->kullanici($acik, ['view']))
            ->get(route('ip-addresses.index', ['kayit' => $gizli->uuid]))
            ->assertOk()
            ->assertDontSee('gizli-sunucu')
            ->assertDontSee('203.0.113.5');
    }

    #[Test]
    public function sirali_anahtar_sayfaya_basilmaz(): void
    {
        $grup = $this->grup('Grup A');
        $adres = $this->adres($grup, '192.0.2.10');

        $this->actingAs($this->kullanici($grup, ['view']))
            ->get(route('ip-addresses.index', ['grup' => $grup->uuid, 'kayit' => $adres->uuid]))
            ->assertOk()
            ->assertSee($adres->uuid)
            ->assertDontSee('kayit='.$adres->id.'"', false);
    }

    /* ── Yazma ── */

    #[Test]
    public function yetkili_adres_ekler_ve_kayda_gecer(): void
    {
        $grup = $this->grup('Dış IPv4', GroupKind::Ipv4);
        $this->adres($grup, '192.0.2.0/24');

        $this->actingAs($this->kullanici($grup, ['view', 'create']))
            ->post(route('ip-addresses.store'), $this->form($grup, '192.0.2.10'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ip_addresses', ['address' => '192.0.2.10', 'prefix' => 32, 'name' => 'web-01']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'created']);
    }

    #[Test]
    public function izleyici_adres_ekleyemez(): void
    {
        $grup = $this->grup('Grup A');

        $this->actingAs($this->kullanici($grup, ['view']))
            ->post(route('ip-addresses.store'), $this->form($grup, '192.0.2.10'))
            ->assertForbidden();

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    /** A'ya ekleme yetkisi, B'nin uuid'iyle B'ye eklemeyi açmaz. */
    #[Test]
    public function baska_grubun_uuidiyle_eklenemez(): void
    {
        $acik = $this->grup('Grup A');
        $kapali = $this->grup('Grup B');

        $this->actingAs($this->kullanici($acik, ['view', 'create']))
            ->post(route('ip-addresses.store'), $this->form($kapali, '192.0.2.10'))
            ->assertSessionHasErrors('group');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    #[Test]
    public function grup_turune_uymayan_adres_reddedilir(): void
    {
        $grup = $this->grup('Dış IPv4', GroupKind::Ipv4);
        $kullanici = $this->kullanici($grup, ['view', 'create']);

        foreach (['10.20.0.1', '2001:db8::1', 'sunucu', '192.0.2.0/33'] as $girdi) {
            $this->actingAs($kullanici)
                ->post(route('ip-addresses.store'), $this->form($grup, $girdi))
                ->assertSessionHasErrors('address');
        }

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    /** Yazım farkı aynı adresi ikinci kez sokmaz. */
    #[Test]
    public function ayni_adres_ayni_grupta_ikinci_kez_eklenemez(): void
    {
        $grup = $this->grup('Dış IPv6', GroupKind::Ipv6);
        $this->adres($grup, '2001:db8::1');

        $this->actingAs($this->kullanici($grup, ['view', 'create']))
            ->post(route('ip-addresses.store'), $this->form($grup, '2001:DB8:0::1'))
            ->assertSessionHasErrors('address');

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    #[Test]
    public function baska_grubun_alt_grubu_secilemez(): void
    {
        $grup = $this->grup('Grup A');
        $yabanci = SubGroup::factory()->for($this->grup('Grup B'), 'group')->create();

        $this->actingAs($this->kullanici($grup, ['view', 'create']))
            ->post(route('ip-addresses.store'), $this->form($grup, '192.0.2.10', ['sub_group' => $yabanci->uuid]))
            ->assertSessionHasErrors('sub_group');
    }

    #[Test]
    public function baska_grubun_adresi_duzenlenemez_ve_silinemez(): void
    {
        $acik = $this->grup('Grup A');
        $gizli = $this->adres($this->grup('Grup B'), '203.0.113.5', 'gizli-sunucu');
        $kullanici = $this->kullanici($acik, ['view', 'create', 'update', 'delete']);

        $this->actingAs($kullanici)
            ->put(route('ip-addresses.update', $gizli), $this->form($acik, '203.0.113.5'))
            ->assertForbidden();

        $this->actingAs($kullanici)
            ->delete(route('ip-addresses.destroy', $gizli), ['reason' => 'deneme'])
            ->assertForbidden();

        $this->assertDatabaseHas('ip_addresses', ['uuid' => $gizli->uuid, 'name' => 'gizli-sunucu']);
    }

    /** Kendi adresini ekleme yetkisi olmayan bir gruba taşıyamaz. */
    #[Test]
    public function adres_yetkisiz_gruba_tasinamaz(): void
    {
        $acik = $this->grup('Grup A');
        $kapali = $this->grup('Grup B');
        $adres = $this->adres($acik, '192.0.2.10');

        $this->actingAs($this->kullanici($acik, ['view', 'update']))
            ->put(route('ip-addresses.update', $adres), $this->form($kapali, '192.0.2.10'))
            ->assertSessionHasErrors('group');

        $this->assertTrue($adres->fresh()->group->is($acik));
    }

    /**
     * Taşımak kaynaktan çıkarmaktır: kaynakta "sil" yetkisi olmayan,
     * "düzenle + hedefte ekle" ile grubu boşaltamaz.
     */
    #[Test]
    public function kaynakta_silme_yetkisi_olmadan_tasinamaz(): void
    {
        $kaynak = $this->grup('Grup A');
        $hedef = $this->grup('Grup B');
        $adres = $this->adres($kaynak, '192.0.2.10');

        $kullanici = $this->kullanici($kaynak, ['view', 'update']);
        $this->kullanici($hedef, ['view', 'create'], $kullanici);

        $this->actingAs($kullanici)
            ->put(route('ip-addresses.update', $adres), $this->form($hedef, '192.0.2.10'))
            ->assertSessionHasErrors('group');

        $this->assertTrue($adres->fresh()->group->is($kaynak));
    }

    #[Test]
    public function kaynakta_silme_hedefte_ekleme_yetkisiyle_tasinir(): void
    {
        $kaynak = $this->grup('Grup A');
        $hedef = $this->grup('Grup B');
        $this->adres($hedef, '192.0.2.0/24');
        $adres = $this->adres($kaynak, '192.0.2.10');

        $kullanici = $this->kullanici($kaynak, ['view', 'update', 'delete']);
        $this->kullanici($hedef, ['view', 'create'], $kullanici);

        $this->actingAs($kullanici)
            ->put(route('ip-addresses.update', $adres), $this->form($hedef, '192.0.2.10'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($adres->fresh()->group->is($hedef));
    }

    #[Test]
    public function duzenleme_kendi_grubunda_ekleme_yetkisi_istemez(): void
    {
        $grup = $this->grup('Grup A');
        $this->adres($grup, '192.0.2.0/24');
        $adres = $this->adres($grup, '192.0.2.10');

        $this->actingAs($this->kullanici($grup, ['view', 'update']))
            ->put(route('ip-addresses.update', $adres), $this->form($grup, '192.0.2.11', ['name' => 'web-02']))
            ->assertSessionHasNoErrors();

        $this->assertSame(['192.0.2.11', 'web-02'], [$adres->fresh()->address, $adres->fresh()->name]);
    }

    #[Test]
    public function silme_gerekce_ister_ve_kayda_gecer(): void
    {
        $grup = $this->grup('Grup A');
        $adres = $this->adres($grup, '192.0.2.10');
        $kullanici = $this->kullanici($grup, ['view', 'delete']);

        $this->actingAs($kullanici)
            ->delete(route('ip-addresses.destroy', $adres))
            ->assertSessionHasErrors('reason');

        $this->actingAs($kullanici)
            ->delete(route('ip-addresses.destroy', $adres), ['reason' => 'Sunucu kapatıldı'])
            ->assertRedirect();

        $this->assertDatabaseMissing('ip_addresses', ['uuid' => $adres->uuid]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted', 'reason' => 'Sunucu kapatıldı']);
    }

    /* ── Grup türü değişimi ── */

    /** Tür sonradan değiştirilerek ekleme kuralı arka kapıdan atlanamaz. */
    #[Test]
    public function grup_turu_icindeki_adreslere_uymayan_ture_cevrilemez(): void
    {
        $grup = $this->grup('Karışık');
        $this->adres($grup, '10.20.0.1');

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('groups.update', $grup), ['name' => 'Karışık', 'kind' => GroupKind::Ipv4->value])
            ->assertSessionHasErrors('kind');

        $this->assertSame(GroupKind::Mixed, $grup->fresh()->kind);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('groups.update', $grup), ['name' => 'Karışık', 'kind' => GroupKind::LocalIpv4->value])
            ->assertSessionHasNoErrors();
    }

    /* ── IPv4 blok kuralı (Ipv4BlockRules) ── */

    #[Test]
    public function blok_olmadan_24ten_kucuk_eklenemez(): void
    {
        $grup = $this->grup('Dış IPv4', GroupKind::Ipv4);

        $this->actingAs($this->kullanici($grup, ['view', 'create']))
            ->post(route('ip-addresses.store'), $this->form($grup, '192.0.2.8/29'))
            ->assertSessionHasErrors(['address' => '/29 doğrudan eklenemez; önce bu adresi içeren /24 (ya da daha geniş) bloğu aynı gruba ekleyin.']);

        $this->assertDatabaseMissing('ip_addresses', ['address' => '192.0.2.8']);
    }

    #[Test]
    public function blok_24_ve_genisi_dogrudan_eklenir(): void
    {
        $grup = $this->grup('Dış IPv4', GroupKind::Ipv4);
        $kullanici = $this->kullanici($grup, ['view', 'create']);

        foreach (['192.0.2.0/24', '198.51.100.0/22'] as $blok) {
            $this->actingAs($kullanici)
                ->post(route('ip-addresses.store'), $this->form($grup, $blok, ['name' => $blok]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $grup->ipAddresses()->count());
    }

    /** Başka gruptaki blok bu grubun adresine kapı açmaz. */
    #[Test]
    public function baska_gruptaki_blok_sayilmaz(): void
    {
        $grup = $this->grup('Grup A');
        $this->adres($this->grup('Grup B'), '192.0.2.0/24');

        $this->actingAs($this->kullanici($grup, ['view', 'create']))
            ->post(route('ip-addresses.store'), $this->form($grup, '192.0.2.10'))
            ->assertSessionHasErrors('address');
    }

    #[Test]
    public function ipv6_blok_kuralina_girmez(): void
    {
        $grup = $this->grup('V6', GroupKind::Ipv6);

        $this->actingAs($this->kullanici($grup, ['view', 'create']))
            ->post(route('ip-addresses.store'), $this->form($grup, '2001:db8::10'))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function icinde_kayit_olan_blok_silinemez(): void
    {
        $grup = $this->grup('Grup A');
        $blok = $this->adres($grup, '192.0.2.0/24');
        $this->adres($grup, '192.0.2.10');

        $this->actingAs($this->kullanici($grup, ['view', 'delete']))
            ->delete(route('ip-addresses.destroy', $blok), ['reason' => 'deneme'])
            ->assertSessionHas('error', 'Bu blokta 1 kayıt var; blok kalkarsa onlar bloksuz kalır. Önce onları silin ya da taşıyın.');

        $this->assertModelExists($blok);
    }

    /** İçerideki kaydı başka bir blok da kapsıyorsa bloksuz kalmaz; silinir. */
    #[Test]
    public function kayitlari_baska_blokta_kalan_blok_silinir(): void
    {
        $grup = $this->grup('Grup A');
        $this->adres($grup, '192.0.2.0/23');
        $blok = $this->adres($grup, '192.0.2.0/24');
        $this->adres($grup, '192.0.2.10');

        $this->actingAs($this->kullanici($grup, ['view', 'delete']))
            ->delete(route('ip-addresses.destroy', $blok), ['reason' => 'deneme'])
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($blok);
    }

    /** Düzenleme ikinci yoldur: bloğu kayıtlarını dışarıda bırakacak yere taşımak da kapalı. */
    #[Test]
    public function icinde_kayit_olan_blok_baska_yere_tasinamaz(): void
    {
        $grup = $this->grup('Grup A');
        $blok = $this->adres($grup, '192.0.2.0/24');
        $this->adres($grup, '192.0.2.10');

        $this->actingAs($this->kullanici($grup, ['view', 'update']))
            ->put(route('ip-addresses.update', $blok), $this->form($grup, '198.51.100.0/24'))
            ->assertSessionHasErrors('address');

        $this->assertSame('192.0.2.0', $blok->fresh()->address);
    }

    #[Test]
    public function blok_adi_degisebilir(): void
    {
        $grup = $this->grup('Grup A');
        $blok = $this->adres($grup, '192.0.2.0/24');
        $this->adres($grup, '192.0.2.10');

        $this->actingAs($this->kullanici($grup, ['view', 'update']))
            ->put(route('ip-addresses.update', $blok), $this->form($grup, '192.0.2.0/24', ['name' => 'yeni-ad']))
            ->assertSessionHasNoErrors();

        $this->assertSame('yeni-ad', $blok->fresh()->name);
    }
}
