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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adres listesinde /24 bloklar: katlanır satır, parçalar altında tablo,
 * böl / bölmeyi kaldır sağdaki ayrıntıda (bkz. Ipv4BlockPlan).
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849).
 */
class BlockListTest extends TestCase
{
    use RefreshDatabase;

    private AddressGroup $grup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grup = AddressGroup::factory()->create(['name' => 'Grup A', 'kind' => GroupKind::Mixed]);
    }

    private function adres(string $girdi, ?string $ad = null): IpAddress
    {
        return IpAddress::factory()->for($this->grup, 'group')->address($girdi)->create(['name' => $ad]);
    }

    /** @param  list<string>  $aksiyonlar */
    private function kullanici(array $aksiyonlar = ['view']): User
    {
        $kullanici = User::factory()->create();

        foreach ($aksiyonlar as $aksiyon) {
            UserPermission::create([
                'user_id' => $kullanici->id,
                'resource' => IpAddressPolicy::RESOURCE,
                'resource_id' => $this->grup->id,
                'action' => $aksiyon,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return $kullanici;
    }

    /** @param  array<string, string|null>  $ek */
    private function liste(User $kullanici, array $ek = []): TestResponse
    {
        return $this->actingAs($kullanici)->get(route('ip-addresses.index', ['grup' => $this->grup->uuid, ...$ek]));
    }

    #[Test]
    public function bolunmus_blok_kapali_gelir_parcalar_tabloda(): void
    {
        $this->adres('198.51.100.0/24', 'musteri-a');
        $this->adres('198.51.102.0/24', 'musteri-b');
        $this->adres('198.51.102.0/25', 'baglanti-x');
        $this->adres('198.51.102.128/25');
        $this->adres('2001:db8::10', 'v6-sunucu');

        $this->liste($this->kullanici())
            ->assertOk()
            ->assertDontSee('Blok planı')
            ->assertSeeInOrder(['198.51.100.0/24', 'musteri-a', 'Boş', 'Bölünmedi', '198.51.102.0/24', 'musteri-b', '%50 dolu', '2 parça'])
            ->assertSeeInOrder(['198.51.102.0/25', '198.51.102.1', '198.51.102.2', '198.51.102.126', '255.255.255.128', 'baglanti-x', 'Dolu'])
            ->assertSeeInOrder(['2 parça', 'v6-sunucu'])
            ->assertSee('<details class="block-node" >', false);
    }

    /** Seçili kayıt bir parçaysa bloğu açık gelir; bloğun kendisi seçiliyse kapalı kalır. */
    #[Test]
    public function secili_parca_blogunu_acar(): void
    {
        $blok = $this->adres('198.51.100.0/24');
        $parca = $this->adres('198.51.100.0/25');
        $this->adres('198.51.100.128/25');

        $this->liste($this->kullanici(), ['kayit' => $parca->uuid])
            ->assertSee('<details class="block-node"  open >', false);

        $this->liste($this->kullanici(), ['kayit' => $blok->uuid])
            ->assertSee('<details class="block-node" >', false);
    }

    /** Arama sonucu düz listedir: eşleşen parça, bloğu eşleşmese de görünür. */
    #[Test]
    public function aramada_parca_duz_satirdir(): void
    {
        $this->adres('198.51.100.0/24', 'musteri-blok');
        $this->adres('198.51.100.0/25', 'aranan-parca');
        $this->adres('198.51.100.128/25');

        $this->liste($this->kullanici(), ['q' => 'aranan'])
            ->assertOk()
            ->assertSee('aranan-parca')
            ->assertDontSee('musteri-blok')
            ->assertDontSee('block-node');
    }

    /** Böl düğmesi ve penceresi yalnız bölebilene; izleyici adresi elle yazsa da açılmaz. */
    #[Test]
    public function bol_ayrintida_yalniz_yetkiliye(): void
    {
        $blok = $this->adres('198.51.100.0/24');
        $pencere = ['kayit' => $blok->uuid, 'pencere' => 'bol'];

        $this->liste($this->kullanici(), $pencere)
            ->assertOk()
            ->assertDontSee('data-modal-open="block-split"', false)
            ->assertDontSee(route('ip-addresses.split', $blok), false);

        $this->liste($this->kullanici(['view', 'update', 'create']), $pencere)
            ->assertOk()
            ->assertSee('data-modal-open="block-split"', false)
            ->assertSee(route('ip-addresses.split', $blok), false)
            ->assertSee('198.51.100.0/24 bölünecek');
    }

    /** Bölünmüş blokta "Sil" yok (reddedilecekti), "Bölmeyi kaldır" var; Böl yok. */
    #[Test]
    public function bolunmus_blogun_ayrintisinda_bolmeyi_kaldir(): void
    {
        $blok = $this->adres('198.51.100.0/24');
        $this->adres('198.51.100.0/25');
        $this->adres('198.51.100.128/25');

        $this->liste($this->kullanici(['view', 'update', 'create', 'delete']), ['kayit' => $blok->uuid])
            ->assertOk()
            ->assertSee(route('ip-addresses.unsplit', $blok), false)
            ->assertSee('2 parça adlarıyla birlikte silinecek')
            ->assertDontSee('Adresi sil')
            ->assertDontSee('data-modal-open="block-split"', false);
    }

    /** Karşılaştırma: bölünmemiş blok silinebilir, "Adresi sil" görünür. */
    #[Test]
    public function bolunmemis_blok_silinebilir(): void
    {
        $blok = $this->adres('198.51.100.0/24');

        $this->liste($this->kullanici(['view', 'delete']), ['kayit' => $blok->uuid])
            ->assertOk()
            ->assertSee('Adresi sil')
            ->assertDontSee(route('ip-addresses.unsplit', $blok), false);
    }

    #[Test]
    public function parcanin_ayrintisinda_sil_yok(): void
    {
        $this->adres('198.51.100.0/24');
        $parca = $this->adres('198.51.100.0/25');
        $this->adres('198.51.100.128/25');

        $this->liste($this->kullanici(['view', 'update', 'delete']), ['kayit' => $parca->uuid])
            ->assertOk()
            ->assertDontSee('Adresi sil')
            ->assertSee('data-modal-open="record-edit"', false);
    }
}
