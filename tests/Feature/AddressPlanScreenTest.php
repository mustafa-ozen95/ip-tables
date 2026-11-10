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
    public function bloklar_kapali_listelenir_parcalar_tabloda(): void
    {
        $grup = $this->grup('Grup A');
        $this->adres($grup, '198.51.100.0/24', 'musteri-a');
        $blok = $this->adres($grup, '198.51.102.0/24', 'musteri-b');
        $this->adres($grup, '198.51.102.0/25', 'baglanti-x');
        $this->adres($grup, '198.51.102.128/25');

        $this->actingAs($this->izleyici($grup))
            ->get(route('ip-addresses.plan', ['grup' => $grup->uuid]))
            ->assertOk()
            ->assertDontSee('/22')
            ->assertSeeInOrder(['198.51.100.0/24', 'musteri-a', 'Bölünmedi', 'Boş', '198.51.102.0/24', 'musteri-b', '2 parça', '%50 dolu'])
            ->assertSeeInOrder(['198.51.102.0/25', '198.51.102.1', '198.51.102.2', '198.51.102.126', '255.255.255.128', 'baglanti-x', 'Dolu'])
            ->assertSee('<details class="block-node" >', false);

        /* Bölmeden dönüşte o blok açık gelir. */
        $this->actingAs($this->izleyici($grup))
            ->get(route('ip-addresses.plan', ['grup' => $grup->uuid, 'blok' => $blok->uuid]))
            ->assertSee('<details class="block-node"  open >', false);
    }

    #[Test]
    public function bloga_bagli_olmayan_kayit_ayri_listede(): void
    {
        $grup = $this->grup('Grup A');
        $this->adres($grup, '203.0.113.5', 'eski-sunucu');

        $this->actingAs($this->izleyici($grup))
            ->get(route('ip-addresses.plan', ['grup' => $grup->uuid]))
            ->assertOk()
            ->assertSeeInOrder(['Bloğa bağlı olmayan kayıtlar', '203.0.113.5', 'eski-sunucu']);
    }

    #[Test]
    public function yetkisiz_grubun_adresi_planda_gorunmez(): void
    {
        $acik = $this->grup('Grup A');
        $kapali = $this->grup('Grup B');
        $this->adres($acik, '198.51.100.0/24', 'gorunen-sunucu');
        $this->adres($kapali, '203.0.113.0/24', 'gizli-sunucu');

        $this->actingAs($this->izleyici($acik))
            ->get(route('ip-addresses.plan'))
            ->assertOk()
            ->assertSee('gorunen-sunucu')
            ->assertDontSee('gizli-sunucu')
            ->assertDontSee('203.0.113.0/24')
            ->assertDontSee('Grup B');
    }

    /** Grup parametresine yetkisiz grubun uuid'i yazmak onu açmaz; "Hepsi"ye düşer. */
    #[Test]
    public function baska_grubun_uuidi_plani_acmaz(): void
    {
        $acik = $this->grup('Grup A');
        $kapali = $this->grup('Grup B');
        $this->adres($kapali, '203.0.113.0/24', 'gizli-sunucu');

        $this->actingAs($this->izleyici($acik))
            ->get(route('ip-addresses.plan', ['grup' => $kapali->uuid]))
            ->assertOk()
            ->assertDontSee('gizli-sunucu')
            ->assertDontSee('203.0.113.0/24');
    }

    /** Böl düğmesi ve penceresi yalnız bölebilene; izleyici adresi elle yazsa da pencere açılmaz. */
    #[Test]
    public function bol_penceresi_yalniz_yetkiliye(): void
    {
        $grup = $this->grup('Grup A');
        $blok = $this->adres($grup, '198.51.100.0/24');
        $pencere = route('ip-addresses.plan', ['grup' => $grup->uuid, 'pencere' => 'bol', 'blok' => $blok->uuid]);

        $this->actingAs($this->izleyici($grup))
            ->get($pencere)
            ->assertOk()
            ->assertDontSee('>Böl</a>', false)
            ->assertDontSee(route('ip-addresses.split', $blok), false);

        $bolen = $this->izleyici($grup);
        foreach (['update', 'create'] as $aksiyon) {
            UserPermission::create([
                'user_id' => $bolen->id, 'resource' => IpAddressPolicy::RESOURCE, 'resource_id' => $grup->id,
                'action' => $aksiyon, 'effect' => PermissionEffect::Allow,
            ]);
        }

        $this->actingAs($bolen)
            ->get($pencere)
            ->assertOk()
            ->assertSee('>Böl</a>', false)
            ->assertSee(route('ip-addresses.split', $blok), false)
            ->assertSee('198.51.100.0/24 bölünecek');
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
