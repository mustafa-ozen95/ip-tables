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
use App\Services\GatewayPing;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gateway ping: bölünmüş blokta parçaların gateway'i, bölünmemiş /24'te .1.
 * Yanıt veren kullanılıyor sayılır, listede yeşil/kırmızı nokta.
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849); ping atılmaz,
 * süreç sahtedir.
 */
class GatewayPingTest extends TestCase
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

    /**
     * Yalnız $yanitlayan adreslerden yanıt gelir; atılan pingler toplanır.
     *
     * @param  list<string>  $yanitlayan
     * @return list<string> ping atılan adresler
     */
    private function pingle(array $yanitlayan): array
    {
        $atilan = [];

        Process::fake(function (PendingProcess $islem) use ($yanitlayan, &$atilan) {
            $adres = end($islem->command);
            $atilan[] = $adres;

            return Process::result(exitCode: in_array($adres, $yanitlayan, true) ? 0 : 1);
        });

        $this->artisan('gateways:ping')->assertSuccessful();
        sort($atilan);

        return $atilan;
    }

    #[Test]
    public function bolunmus_blokta_parca_gatewayleri_bolunmemiste_nokta_bir(): void
    {
        $this->adres('198.51.100.0/24');
        $this->adres('198.51.100.0/29');
        $this->adres('198.51.100.8/29');
        $this->adres('198.51.100.16/31');
        $this->adres('198.51.100.18');
        $this->adres('203.0.113.0/24');
        $this->adres('192.0.2.10');
        $this->adres('2001:db8::/64');

        /* Boş alan (.20–.255), /31, /32, blok dışı adres ve IPv6 pinglenmez. */
        $this->assertSame(['198.51.100.1', '198.51.100.9', '203.0.113.1'], $this->pingle([]));
    }

    #[Test]
    public function sonuc_kayda_yazilir_tarih_degismez(): void
    {
        $acik = $this->adres('198.51.100.0/24');
        $kapali = $this->adres('203.0.113.0/24');
        $once = $acik->updated_at;

        $this->travel(5)->minutes();
        $this->pingle(['198.51.100.1']);

        $acik->refresh();
        $kapali->refresh();
        $this->assertTrue($acik->gateway_up);
        $this->assertNotNull($acik->gateway_seen_at);
        $this->assertFalse($kapali->gateway_up);
        $this->assertNotNull($kapali->gateway_checked_at);
        $this->assertNull($kapali->gateway_seen_at);
        $this->assertEquals($once, $acik->updated_at);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    /** Sonradan bölünen bloğun kendi sonucu düşer; yerini parçalarınki alır. */
    #[Test]
    public function hedef_olmayan_kaydin_noktasi_kalmaz(): void
    {
        $blok = $this->adres('198.51.100.0/24');
        $this->pingle(['198.51.100.1']);
        $this->assertTrue($blok->refresh()->gateway_up);

        $parca = $this->adres('198.51.100.0/25');
        $this->adres('198.51.100.128/25');
        $this->pingle(['198.51.100.1']);

        $this->assertNull($blok->refresh()->gateway_up);
        $this->assertNull($blok->gateway_checked_at);
        $this->assertTrue($parca->refresh()->gateway_up);
    }

    #[Test]
    public function kapaliysa_ping_atilmaz(): void
    {
        config(['ip.gateway_ping.enabled' => false]);
        $this->adres('198.51.100.0/24');

        $this->assertSame([], $this->pingle([]));
        Process::assertNothingRan();
    }

    /** Komut kabuktan geçmez: dizi, adres son öğe. */
    #[Test]
    public function komut_dizi_olarak_calisir(): void
    {
        $this->adres('198.51.100.0/24');
        Process::fake();

        app(GatewayPing::class)->run();

        Process::assertRan(fn (PendingProcess $islem): bool => $islem->command === ['ping', '-n', '-c', '1', '-W', '1', '198.51.100.1']);
    }

    #[Test]
    public function on_dakikada_bir_zamanlanir(): void
    {
        $olay = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'gateways:ping'));

        $this->assertNotNull($olay);
        $this->assertSame('*/10 * * * *', $olay->expression);
    }

    /** Ping alan adsız parça "Kullanılıyor"; listede yeşil/kırmızı nokta, blok doluluğuna girer. */
    #[Test]
    public function listede_nokta_ve_kullaniliyor(): void
    {
        $this->adres('198.51.100.0/24');
        $this->adres('198.51.100.0/25');
        $this->adres('198.51.100.128/25');
        $this->adres('203.0.113.0/24');
        $this->pingle(['198.51.100.1', '203.0.113.1']);

        $this->actingAs($this->izleyici())
            ->get(route('ip-addresses.index', ['grup' => $this->grup->uuid, 'blok' => IpAddress::query()->where('address', '198.51.100.0')->where('prefix', 24)->value('uuid')]))
            ->assertOk()
            ->assertSeeInOrder(['198.51.100.0/24', '%50 dolu'])
            ->assertSeeInOrder(['198.51.100.0/25', 'dot--green', 'Gateway yanıt veriyor', 'Kullanılıyor'])
            ->assertSeeInOrder(['198.51.100.128/25', 'dot--red', 'Gateway yanıt vermiyor · hiç yanıt yok', 'Boş'])
            ->assertSeeInOrder(['203.0.113.0/24', 'dot--green', 'Kullanılıyor'])
            ->assertSee('ping-dot', false);
    }

    private function izleyici(): User
    {
        $kullanici = User::factory()->create();
        UserPermission::create([
            'user_id' => $kullanici->id,
            'resource' => IpAddressPolicy::RESOURCE,
            'resource_id' => $this->grup->id,
            'action' => 'view',
            'effect' => PermissionEffect::Allow,
        ]);

        return $kullanici;
    }
}
