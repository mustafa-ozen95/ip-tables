<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
use App\Enums\PermissionEffect;
use App\Jobs\GatewayPingRoundJob;
use App\Jobs\PingGatewayJob;
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
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gateway ping: bölünmüş blokta parçaların gateway'i, bölünmemiş /24'te .1.
 * Yanıt veren kullanılıyor sayılır, listede yeşil/kırmızı nokta.
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849); ping atılmaz,
 * süreç sahtedir. Testte kuyruk eşzamanlıdır: ping işleri hemen çalışır;
 * tur kendini yeniden kurduğu için sahtedir (yoksa sonsuz döner).
 */
class GatewayPingTest extends TestCase
{
    use RefreshDatabase;

    private AddressGroup $grup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grup = AddressGroup::factory()->create(['name' => 'Grup A', 'kind' => GroupKind::Mixed]);
        Queue::fake([GatewayPingRoundJob::class]);
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

        app(GatewayPing::class)->check(1, '198.51.100.1');

        Process::assertRan(fn (PendingProcess $islem): bool => $islem->command === ['ping', '-n', '-c', '1', '-W', '1', '198.51.100.1']);
    }

    /** Tur pingleri spread'e eşit aralıkla yayar, sonraki turu interval sonrasına kurar; zamanlayıcıda değildir. */
    #[Test]
    public function tur_kuyrukta_yayilir_ve_kendini_kurar(): void
    {
        Queue::fake();
        $this->freezeTime();
        config(['ip.gateway_ping.spread' => 300, 'ip.gateway_ping.interval' => 600]);
        $this->adres('192.0.2.0/24');
        $this->adres('198.51.100.0/24');
        $this->adres('203.0.113.0/24');

        $this->artisan('gateways:ping')->expectsOutputToContain('3 gateway için ping kuyruğa alındı')->assertSuccessful();

        $gecikme = fn ($is): int => (int) now()->diffInSeconds($is->delay);
        $pingler = Queue::pushed(PingGatewayJob::class)->mapWithKeys(fn (PingGatewayJob $is): array => [$is->gateway => $gecikme($is)]);
        $this->assertSame(['192.0.2.1' => 0, '198.51.100.1' => 100, '203.0.113.1' => 200], $pingler->all());

        Queue::assertPushed(GatewayPingRoundJob::class, 1);
        $this->assertSame(600, $gecikme(Queue::pushed(GatewayPingRoundJob::class)->first()));

        /* Tur çalışınca yine üç ping, yine bir sonraki tur. */
        (new GatewayPingRoundJob)->handle(app(GatewayPing::class));
        Queue::assertPushed(PingGatewayJob::class, 6);

        $this->assertFalse(collect(app(Schedule::class)->events())
            ->contains(fn ($e): bool => str_contains((string) $e->command, 'gateways:ping')));
    }

    /** Bekleyen tur varken ikinci tur kuyruğa girmez (açılış, ↻). */
    #[Test]
    public function bekleyen_tur_varken_ikincisi_kurulmaz(): void
    {
        Queue::fake();

        app(GatewayPing::class)->scheduleNextRound();
        app(GatewayPing::class)->scheduleNextRound();

        Queue::assertPushed(GatewayPingRoundJob::class, 1);
    }

    #[Test]
    public function kapaliysa_tur_kendini_kurmaz(): void
    {
        Queue::fake();
        config(['ip.gateway_ping.enabled' => false]);
        $this->adres('198.51.100.0/24');

        (new GatewayPingRoundJob)->handle(app(GatewayPing::class));

        Queue::assertNothingPushed();
    }

    /** ↻: ayrı "ping" yetkisi; gateway'i hemen kuyruğa atar. */
    #[Test]
    public function simdi_kontrol_et_ayri_yetki_ister(): void
    {
        Queue::fake();
        $blok = $this->adres('198.51.100.0/24');
        $izleyici = $this->izleyici();
        $pingci = $this->izleyici(['ping']);

        $this->actingAs($izleyici)->get(route('ip-addresses.index', ['grup' => $this->grup->uuid]))
            ->assertOk()->assertDontSee(route('ip-addresses.ping', $blok), false);
        $this->actingAs($izleyici)->post(route('ip-addresses.ping', $blok))->assertForbidden();
        Queue::assertNothingPushed();

        $this->actingAs($pingci)->get(route('ip-addresses.index', ['grup' => $this->grup->uuid]))
            ->assertOk()->assertSee(route('ip-addresses.ping', $blok), false)->assertSee('Şimdi kontrol et');
        $this->actingAs($pingci)->post(route('ip-addresses.ping', $blok))
            ->assertSessionHas('status', '198.51.100.1 için ping kuyruğa alındı; sonuç birkaç saniye içinde noktada görünür.');

        Queue::assertPushed(PingGatewayJob::class, fn (PingGatewayJob $is): bool => $is->recordId === $blok->id && $is->gateway === '198.51.100.1');
    }

    #[Test]
    public function gatewayi_olmayana_ping_atilmaz(): void
    {
        Queue::fake();
        $this->adres('198.51.100.0/24');
        $tek = $this->adres('198.51.100.7');
        $v6 = $this->adres('2001:db8::/64');
        $pingci = $this->izleyici(['ping']);

        foreach ([$tek, $v6] as $kayit) {
            $this->actingAs($pingci)->post(route('ip-addresses.ping', $kayit))
                ->assertSessionHas('error', "{$kayit->notation} için pinglenecek gateway yok.");
        }

        Queue::assertNotPushed(PingGatewayJob::class);
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

    /** @param  list<string>  $ek  görüntüle dışındaki yetkiler */
    private function izleyici(array $ek = []): User
    {
        $kullanici = User::factory()->create();

        foreach (['view', ...$ek] as $yetki) {
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
}
