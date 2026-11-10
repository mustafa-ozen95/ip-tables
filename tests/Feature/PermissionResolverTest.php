<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Yetki kararının sırası.
 *
 * İKİ KATMAN VAR: rol (canlı) ve kişi (istisna). Buradaki testlerin çoğu
 * KİŞİ katmanını yalıtarak sınar — kullanıcı, hiçbir şey vermeyen bir rolde
 * açılır — ve sonundaki bölüm iki katmanın birbirine göre sırasını kilitler.
 *
 * Panelin tamamı bu sıraya dayanıyor ama tek bir testi yoktu: "hepsinde
 * görsün, biri hariç" kuralının çalıştığını hiçbir şey kanıtlamıyordu.
 * Kararlar istek boyunca bir tabloda tutuluyor (hız için); bu testler hem
 * sırayı hem de tablonun bayatlamadığını kilitler.
 */
class PermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private const RESOURCE = IpAddressPolicy::RESOURCE;

    private function resolver(): UserPermissionResolver
    {
        return app(UserPermissionResolver::class);
    }

    /**
     * Rolü HİÇBİR ŞEY VERMEYEN kullanıcı.
     *
     * Kişi katmanını yalıtmak için: rol canlı okunduğundan, şablonu dolu bir
     * rolde açılan kullanıcı zaten yetkili olurdu ve "eşleşme yoksa kapalı"
     * gibi sorular ölçülemezdi. Rol katmanının kendi testleri aşağıda.
     */
    private function kullanici(): User
    {
        $user = User::factory()->create();
        $user->role->update(['template' => []]);

        return $user->fresh();
    }

    private function izin(User $user, int $recordId, string $action, PermissionEffect $effect): void
    {
        $user->permissions()->create([
            'resource' => self::RESOURCE,
            'resource_id' => $recordId,
            'action' => $action,
            'effect' => $effect,
        ]);

        $this->resolver()->flush($user);
    }

    #[Test]
    public function eslesme_yoksa_varsayilan_kapalidir(): void
    {
        $user = $this->kullanici();
        $group = AddressGroup::factory()->create();

        $this->assertFalse($this->resolver()->allows($user, self::RESOURCE, 'view', $group->getKey()));
    }

    #[Test]
    public function genel_izin_butun_gruplarda_gecerlidir(): void
    {
        $user = $this->kullanici();
        $group = AddressGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Allow);

        $this->assertTrue($this->resolver()->allows($user, self::RESOURCE, 'view', $group->getKey()));

        /* Sonradan açılan grup da kurala kendiliğinden uyar. */
        $yeni = AddressGroup::factory()->create();

        $this->assertTrue($this->resolver()->allows($user, self::RESOURCE, 'view', $yeni->getKey()));
    }

    /** "Hepsinde görsün, biri hariç": kayda özel yasak genel izni KESER. */
    #[Test]
    public function kayda_ozel_yasak_genel_izni_keser(): void
    {
        $user = $this->kullanici();
        $acik = AddressGroup::factory()->create();
        $kapali = AddressGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Allow);
        $this->izin($user, $kapali->getKey(), 'view', PermissionEffect::Deny);

        $resolver = $this->resolver();

        $this->assertTrue($resolver->allows($user, self::RESOURCE, 'view', $acik->getKey()));
        $this->assertFalse($resolver->allows($user, self::RESOURCE, 'view', $kapali->getKey()));
    }

    #[Test]
    public function kayda_ozel_izin_genel_yasagi_asar(): void
    {
        $user = $this->kullanici();
        $group = AddressGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Deny);
        $this->izin($user, $group->getKey(), 'view', PermissionEffect::Allow);

        $this->assertTrue($this->resolver()->allows($user, self::RESOURCE, 'view', $group->getKey()));
    }

    /** Düzenleme izni 'view' iznini KAPSAMAZ, ona eklenir. */
    #[Test]
    public function duzenleme_izni_view_olmadan_ise_yaramaz(): void
    {
        $user = $this->kullanici();
        $group = AddressGroup::factory()->create();

        $this->izin($user, $group->getKey(), 'update', PermissionEffect::Allow);

        $this->assertFalse($this->resolver()->allows($user, self::RESOURCE, 'update', $group->getKey()));

        $this->izin($user, $group->getKey(), 'view', PermissionEffect::Allow);

        $this->assertTrue($this->resolver()->allows($user, self::RESOURCE, 'update', $group->getKey()));
    }

    #[Test]
    public function sorgu_daraltmasi_kayda_ozel_yasagi_uygular(): void
    {
        $user = $this->kullanici();
        $acik = AddressGroup::factory()->create();
        $kapali = AddressGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Allow);
        $this->izin($user, $kapali->getKey(), 'view', PermissionEffect::Deny);

        IpAddress::factory()->for($acik, 'group')->create(['name' => 'Gorunur']);
        IpAddress::factory()->for($kapali, 'group')->create(['name' => 'Gizli']);

        $adlar = IpAddress::query()->visibleTo($user)->pluck('name')->all();

        $this->assertSame(['Gorunur'], $adlar);
    }

    #[Test]
    public function pasif_kullanici_hicbir_seye_erisemez(): void
    {
        $user = User::factory()->passive()->create();
        $group = AddressGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Allow);

        $resolver = $this->resolver();

        $this->assertFalse($resolver->allows($user, self::RESOURCE, 'view', $group->getKey()));
        $this->assertFalse($resolver->allowsAny($user, self::RESOURCE, 'view'));
    }

    /**
     * Karar tablosu AYNI İSTEK içinde bayatlamamalı.
     *
     * Kararlar hız için tabloda tutuluyor. İzin ekranı önce mevcut durumu
     * sorup sonra yazıyor; tablo düşmeseydi kullanıcı yeni yetkiyi kendi
     * yaptığı işlemden sonra göremezdi.
     */
    #[Test]
    public function izin_degisikligi_ayni_istekte_gorunur(): void
    {
        $user = $this->kullanici();
        $group = AddressGroup::factory()->create();
        $resolver = $this->resolver();

        $this->assertFalse($resolver->allows($user, self::RESOURCE, 'view', $group->getKey()));

        $resolver->syncResource($user, self::RESOURCE, [], ['view' => [$group->getKey()]]);

        $this->assertTrue(
            $resolver->allows($user, self::RESOURCE, 'view', $group->getKey()),
            'syncResource sonrası karar tablosu tazelenmeli.',
        );

        $resolver->syncResource($user, self::RESOURCE, [], []);

        $this->assertFalse(
            $resolver->allows($user, self::RESOURCE, 'view', $group->getKey()),
            'Kaldırılan yetki aynı istekte de kalkmalı.',
        );
    }

    #[Test]
    public function ayri_kullanicilarin_kararlari_birbirine_karismaz(): void
    {
        $yetkili = $this->kullanici();
        $yetkisiz = $this->kullanici();
        $group = AddressGroup::factory()->create();

        $this->izin($yetkili, $group->getKey(), 'view', PermissionEffect::Allow);

        $resolver = $this->resolver();

        $this->assertTrue($resolver->allows($yetkili, self::RESOURCE, 'view', $group->getKey()));
        $this->assertFalse($resolver->allows($yetkisiz, self::RESOURCE, 'view', $group->getKey()));
    }

    /* ── Rol katmanı ── */

    /**
     * ROL CANLI OKUNUR: kullanıcıya kopyalanmaz.
     *
     * Kopya eskirdi: rolü düzenleyen ekranda yeni yetkiyi görür, kullanıcılar
     * eski yetkiyle çalışırdı.
     */
    #[Test]
    public function rolun_yetkisi_kullaniciya_gecer(): void
    {
        $user = $this->kullanici();
        $group = AddressGroup::factory()->create(['name' => 'Grup A']);

        $user->role->update(['template' => [
            self::RESOURCE => ['all' => ['view'], 'groups' => []],
        ]]);
        $this->resolver()->flush($user->fresh());

        $this->assertTrue($this->resolver()->allows($user->fresh(), self::RESOURCE, 'view', $group->getKey()));
    }

    /** Rol düzenlenince kullanıcı için ANINDA geçerli olur. */
    #[Test]
    public function rol_degisince_yetki_hemen_degisir(): void
    {
        $user = $this->kullanici();
        $group = AddressGroup::factory()->create();

        $user->role->update(['template' => [self::RESOURCE => ['all' => ['view'], 'groups' => []]]]);
        $this->resolver()->flush($user->fresh());
        $this->assertTrue($this->resolver()->allows($user->fresh(), self::RESOURCE, 'view', $group->getKey()));

        $user->role->update(['template' => []]);
        $this->resolver()->flush($user->fresh());
        $this->assertFalse($this->resolver()->allows($user->fresh(), self::RESOURCE, 'view', $group->getKey()));
    }

    /** Rol yalnızca ADI GEÇEN grupta yetki verebilir. */
    #[Test]
    public function rol_grup_bazinda_yetki_verebilir(): void
    {
        $user = $this->kullanici();
        $grupA = AddressGroup::factory()->create(['name' => 'Grup A']);
        $grupB = AddressGroup::factory()->create(['name' => 'Grup B']);

        $user->role->update(['template' => [
            self::RESOURCE => ['all' => [], 'groups' => ['Grup A' => ['view']]],
        ]]);
        $this->resolver()->flush($user->fresh());

        $resolver = $this->resolver();

        $this->assertTrue($resolver->allows($user->fresh(), self::RESOURCE, 'view', $grupA->getKey()));
        $this->assertFalse($resolver->allows($user->fresh(), self::RESOURCE, 'view', $grupB->getKey()));
    }

    /**
     * KİŞİNİN YASAĞI ROLÜ KESER.
     *
     * "Bu kişide olmasın" demenin yolu yoksa istisna diye bir şey yoktur.
     */
    #[Test]
    public function kisisel_yasak_rolun_verdigini_keser(): void
    {
        $user = $this->kullanici();
        $grupA = AddressGroup::factory()->create(['name' => 'Grup A']);
        $grupB = AddressGroup::factory()->create(['name' => 'Grup B']);

        $user->role->update(['template' => [self::RESOURCE => ['all' => ['view'], 'groups' => []]]]);
        $this->izin($user, $grupA->getKey(), 'view', PermissionEffect::Deny);

        $resolver = $this->resolver();

        $this->assertFalse($resolver->allows($user->fresh(), self::RESOURCE, 'view', $grupA->getKey()));
        $this->assertTrue($resolver->allows($user->fresh(), self::RESOURCE, 'view', $grupB->getKey()));
    }

    /** Kişisel izin, rolün vermediğini ekler. */
    #[Test]
    public function kisisel_izin_rolun_vermedigini_ekler(): void
    {
        $user = $this->kullanici();
        $grupA = AddressGroup::factory()->create(['name' => 'Grup A']);

        $this->izin($user, $grupA->getKey(), 'view', PermissionEffect::Allow);

        $this->assertTrue($this->resolver()->allows($user->fresh(), self::RESOURCE, 'view', $grupA->getKey()));
    }

    /**
     * SÜZME VERİTABANINDA: rolün verdiği kayıtlar sorguda da görünür,
     * kişinin yasakladıkları görünmez.
     */
    #[Test]
    public function sorgu_iki_katmani_birden_uygular(): void
    {
        $user = $this->kullanici();
        $grupA = AddressGroup::factory()->create(['name' => 'Grup A']);
        $grupB = AddressGroup::factory()->create(['name' => 'Grup B']);

        IpAddress::factory()->for($grupA, 'group')->create(['name' => 'srv-a SSH']);
        IpAddress::factory()->for($grupB, 'group')->create(['name' => 'Grup B kaydı']);

        $user->role->update(['template' => [self::RESOURCE => ['all' => ['view'], 'groups' => []]]]);
        $this->izin($user, $grupB->getKey(), 'view', PermissionEffect::Deny);

        $adlar = IpAddress::query()->visibleTo($user->fresh())->pluck('name')->all();

        $this->assertSame(['srv-a SSH'], $adlar);
    }
}
