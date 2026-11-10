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

    /** Kısmen bölünmüş blok: boş alan tabloda satır, Böl durur ve pencere boş alanı önerir. */
    #[Test]
    public function kismen_bolunmus_blok_bos_alani_gosterir(): void
    {
        $blok = $this->adres('198.51.100.0/24');
        $this->adres('198.51.100.0/26');

        $yanit = $this->liste($this->kullanici(['view', 'update', 'create']), ['kayit' => $blok->uuid, 'pencere' => 'bol']);

        /*
         * Sıra: subnet, adet, aralık. Subnet yalnız boş alana sığanlar; adet
         * subnete, aralık adede bağlıdır (data-linked) ve yalnız boş yerleri taşır.
         */
        $html = $yanit->getContent();
        $this->assertSame(1, preg_match('/split-0-prefix.*split-0-count.*split-0-slot/s', $html));

        $subnet = $this->secici($html, 'split-0-prefix');
        $this->assertStringContainsString('/25 · 125 kullanılabilir · 1 boş yer', $subnet);
        $this->assertStringContainsString('/26 · 61 kullanılabilir · 3 boş yer', $subnet);
        $this->assertStringNotContainsString('/31', $subnet);
        $this->assertStringContainsString('/28 · 13 kullanılabilir · 12 boş yer', $subnet);

        /* .64–.255 boş: /26 en çok 3, /27 en çok 6 art arda. */
        $adet = $this->secici($html, 'split-0-count');
        $this->assertStringContainsString('data-linked="ranges[0][prefix]"', $adet);
        $this->assertStringContainsString('value="26:3"', $adet);
        $this->assertStringContainsString('2 parça · 64 adres', $adet);
        $this->assertStringNotContainsString('value="26:4"', $adet);
        $this->assertStringContainsString('&quot;27&quot;:[&quot;27:1&quot;,&quot;27:2&quot;,&quot;27:3&quot;,&quot;27:4&quot;,&quot;27:5&quot;,&quot;27:6&quot;]', $adet);

        $aralik = $this->secici($html, 'split-0-slot');
        $this->assertStringContainsString('data-linked="ranges[0][count]"', $aralik);
        $this->assertStringContainsString('value="64-191"', $aralik);
        $this->assertStringContainsString('198.51.100.64 – .191', $aralik);
        $this->assertStringNotContainsString('value="0-63"', $aralik);
        $this->assertStringContainsString('&quot;26:2&quot;:[&quot;&quot;,&quot;64-191&quot;]', $aralik);
        $this->assertStringContainsString('&quot;27:2&quot;:[&quot;&quot;,&quot;64-127&quot;,&quot;128-191&quot;,&quot;192-255&quot;]', $aralik);

        $yanit
            ->assertOk()
            ->assertSee('1 parça · 192 adres bölünmemiş')
            ->assertSeeInOrder(['198.51.100.0/26', '198.51.100.64 – .255', '192 adres', 'Bölünmemiş'])
            ->assertSee('data-modal-open="block-split"', false)
            ->assertSee('Boş alan: 198.51.100.64 – .255 (192 adres)')
            ->assertSee('name="ranges[0][prefix]"', false)
            ->assertDontSee('name="ranges[1][prefix]"', false)
            ->assertSee('name="onizle"', false);
    }

    /** Sayfadaki bir açılır listenin HTML'i. */
    private function secici(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/<select[^>]*id="'.$id.'".*?<\/select>/s', $html, $m), "{$id} yok");

        return $m[0];
    }

    #[Test]
    public function onizleme_pencerede_gorunur(): void
    {
        $blok = $this->adres('198.51.100.0/24');

        $this->actingAs($this->kullanici(['view', 'update', 'create']))
            ->withSession(['splitPreview' => '8 parça: .0–.63 /29×8'])
            ->get(route('ip-addresses.index', ['grup' => $this->grup->uuid, 'kayit' => $blok->uuid, 'pencere' => 'bol']))
            ->assertSee('Önizleme: 8 parça: .0–.63 /29×8');
    }

    /**
     * Parçanın ayrıntısında birleştir (eşi varsa) ve ikiye ayır. Grupta
     * birden çok /24 var: bloğun grubu tembel yüklenirse burada patlıyordu.
     */
    #[Test]
    public function parcanin_ayrintisinda_birlestir_ve_ikiye_ayir(): void
    {
        $this->adres('198.51.100.0/24');
        $this->adres('203.0.113.0/24');
        $parca = $this->adres('198.51.100.0/29');
        $this->adres('198.51.100.8/29');
        $yalniz = $this->adres('198.51.100.16/28');

        $yetkili = $this->kullanici(['view', 'update', 'create', 'delete']);

        $this->liste($yetkili, ['kayit' => $parca->uuid])
            ->assertSee(route('ip-addresses.merge', $parca), false)
            ->assertSee('198.51.100.0/29 + 198.51.100.8/29 → 198.51.100.0/28')
            ->assertSee(route('ip-addresses.halve', $parca), false)
            ->assertSee('198.51.100.0/29 → 2 × /30');

        $this->liste($yetkili, ['kayit' => $yalniz->uuid])
            ->assertDontSee(route('ip-addresses.merge', $yalniz), false)
            ->assertSee(route('ip-addresses.halve', $yalniz), false);

        $this->liste($this->kullanici(['view']), ['kayit' => $parca->uuid])
            ->assertDontSee(route('ip-addresses.merge', $parca), false)
            ->assertDontSee(route('ip-addresses.halve', $parca), false);
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
