<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
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
 * Blok planı ekranı: liste ekranıyla aynı sınır. Görülmeyen grubun adresi
 * ağaçta da yoktur.
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849).
 */
class AddressPlanScreenTest extends TestCase
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

    private function izleyici(AddressGroup $grup): User
    {
        $kullanici = User::factory()->create();

        UserPermission::create([
            'user_id' => $kullanici->id,
            'resource' => IpAddressPolicy::RESOURCE,
            'resource_id' => $grup->id,
            'action' => 'view',
            'effect' => PermissionEffect::Allow,
        ]);

        return $kullanici;
    }

    #[Test]
    public function grubu_olmayan_plana_giremez(): void
    {
        $this->actingAs(User::factory()->create())->get(route('ip-addresses.plan'))->assertForbidden();
    }

    #[Test]
    public function oturumsuz_girise_yonlenir(): void
    {
        $this->get(route('ip-addresses.plan'))->assertRedirect(route('login'));
    }

    #[Test]
    public function bloklar_agac_olarak_cizilir(): void
    {
        $grup = $this->grup('Grup A');
        $this->adres($grup, '198.51.100.0/24', 'musteri-a');
        $this->adres($grup, '198.51.102.0/24', 'musteri-b');
        $this->adres($grup, '198.51.102.8/30', 'baglanti-x');

        $this->actingAs($this->izleyici($grup))
            ->get(route('ip-addresses.plan', ['grup' => $grup->uuid]))
            ->assertOk()
            ->assertSeeInOrder(['198.51.100.0/22', '198.51.100.0/23', '198.51.100.0/24', 'musteri-a', 'Boş', '198.51.101.0/24', 'Kayıtsız'])
            ->assertSee('198.51.102.8/30')
            ->assertSee('baglanti-x');
    }

    #[Test]
    public function yetkisiz_grubun_adresi_planda_gorunmez(): void
    {
        $acik = $this->grup('Grup A');
        $kapali = $this->grup('Grup B');
        $this->adres($acik, '198.51.100.0/24', 'gorunen-sunucu');
        $this->adres($kapali, '203.0.113.5', 'gizli-sunucu');

        $this->actingAs($this->izleyici($acik))
            ->get(route('ip-addresses.plan'))
            ->assertOk()
            ->assertSee('gorunen-sunucu')
            ->assertDontSee('gizli-sunucu')
            ->assertDontSee('203.0.112.0/22')
            ->assertDontSee('Grup B');
    }

    /** Grup parametresine yetkisiz grubun uuid'i yazmak onu açmaz; "Hepsi"ye düşer. */
    #[Test]
    public function baska_grubun_uuidi_plani_acmaz(): void
    {
        $acik = $this->grup('Grup A');
        $kapali = $this->grup('Grup B');
        $this->adres($kapali, '203.0.113.5', 'gizli-sunucu');

        $this->actingAs($this->izleyici($acik))
            ->get(route('ip-addresses.plan', ['grup' => $kapali->uuid]))
            ->assertOk()
            ->assertDontSee('gizli-sunucu')
            ->assertDontSee('203.0.113.5');
    }

    #[Test]
    public function ipv6_grubunda_plan_yok_der(): void
    {
        $grup = $this->grup('V6', GroupKind::Ipv6);
        $this->adres($grup, '2001:db8::10');

        $this->actingAs($this->izleyici($grup))
            ->get(route('ip-addresses.plan', ['grup' => $grup->uuid]))
            ->assertOk()
            ->assertSee('Blok planı IPv4 adresleri içindir.');
    }

    #[Test]
    public function liste_ekranindan_plana_baglanti_var(): void
    {
        $grup = $this->grup('Grup A');

        $this->actingAs($this->izleyici($grup))
            ->get(route('ip-addresses.index', ['grup' => $grup->uuid]))
            ->assertOk()
            ->assertSee(route('ip-addresses.plan', ['grup' => $grup->uuid]), false);
    }
}
