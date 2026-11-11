<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
use App\Enums\PermissionEffect;
use App\Jobs\GatewayPingRoundJob;
use App\Jobs\PingGatewayJob;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\IpAddressPolicy;
use App\Services\GatewayPing;
use App\Services\Settings\SettingsRepository;
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

    /** Komut kabuktan geçmez: dizi, adres son öğe. Varsayılan 5 ping, saniyede bir; adet ve hız ayardan. */
    #[Test]
    public function komut_dizi_olarak_calisir(): void
    {
        $this->adres('198.51.100.0/24');
        Process::fake();

        app(GatewayPing::class)->check(1, '198.51.100.1');
        config(['ip.gateway_ping.count' => 3, 'ip.gateway_ping.spacing_ms' => 500]);
        app(GatewayPing::class)->check(1, '198.51.100.1');

        Process::assertRan(fn (PendingProcess $islem): bool => $islem->command === ['ping', '-n', '-c', '5', '-i', '1', '-W', '1', '-w', '6', '198.51.100.1']);
        Process::assertRan(fn (PendingProcess $islem): bool => $islem->command === ['ping', '-n', '-c', '3', '-i', '0.5', '-W', '1', '-w', '3', '198.51.100.1']);
    }

    /** Tur aralığı ve ping adedi Ayarlar'dan değişir; sınır dışı değer kabul edilmez. */
    #[Test]
    public function aralik_ve_adet_ayarlardan_degisir(): void
    {
        $rol = Role::query()->where('is_admin', true)->first()
            ?? Role::create(['slug' => 'admin', 'label' => 'Yönetici', 'badge' => 'gray', 'is_admin' => true]);
        $yonetici = User::factory()->create(['role_id' => $rol->getKey()]);

        $this->actingAs($yonetici)->get(route('settings.index', ['grup' => 'ping']))
            ->assertOk()->assertSee('Gateway ping')->assertSee('Tur aralığı')->assertSee('Ping adedi')->assertSee('Ping hızı')->assertSee('Aynı anda pinglenen gateway');

        $this->actingAs($yonetici)->put(route('settings.update'), [
            'group' => 'ping', 'ip__gateway_ping__interval_minutes' => 30, 'ip__gateway_ping__count' => 3,
            'ip__gateway_ping__spacing_ms' => 500, 'ip__gateway_ping__concurrency' => 10,
        ])->assertSessionHasNoErrors();

        app(SettingsRepository::class)->apply();
        $this->assertSame([1800, 3, 500, 10], [GatewayPing::intervalSeconds(), config('ip.gateway_ping.count'),
            config('ip.gateway_ping.spacing_ms'), config('ip.gateway_ping.concurrency')]);

        $this->actingAs($yonetici)->put(route('settings.update'), [
            'group' => 'ping', 'ip__gateway_ping__interval_minutes' => 30, 'ip__gateway_ping__count' => 50,
            'ip__gateway_ping__spacing_ms' => 100, 'ip__gateway_ping__concurrency' => 10,
        ])
            /* 200 ms'den sık ping root ister; sınır dışı adet ve hız kabul edilmez. */
            ->assertSessionHasErrors(['ip__gateway_ping__count', 'ip__gateway_ping__spacing_ms']);
    }

    /** Tur hedefleri paketlere bölüp hemen kuyruğa atar, sonraki turu aralık sonrasına kurar; zamanlayıcıda değildir. */
    #[Test]
    public function tur_kuyrukta_yayilir_ve_kendini_kurar(): void
    {
        Queue::fake();
        $this->freezeTime();
        config(['ip.gateway_ping.interval_minutes' => 10, 'ip.gateway_ping.concurrency' => 2]);
        $this->adres('192.0.2.0/24');
        $this->adres('198.51.100.0/24');
        $this->adres('203.0.113.0/24');

        $this->artisan('gateways:ping')->expectsOutputToContain('3 gateway için ping kuyruğa alındı')->assertSuccessful();

        $gecikme = fn ($is): int => (int) now()->diffInSeconds($is->delay);
        /* İkişerlik paket: ilk ikisi aynı anda, üçüncüsü sonraki pakette; gecikme yok. */
        $paketler = Queue::pushed(PingGatewayJob::class)->map(fn (PingGatewayJob $is): array => array_column($is->targets, 'gateway'));
        $this->assertSame([['192.0.2.1', '198.51.100.1'], ['203.0.113.1']], $paketler->values()->all());
        Queue::assertNotPushed(PingGatewayJob::class, fn (PingGatewayJob $is): bool => $is->queue !== null || $is->delay !== null);

        Queue::assertPushed(GatewayPingRoundJob::class, 1);
        $this->assertSame(600, $gecikme(Queue::pushed(GatewayPingRoundJob::class)->first()));

        /* Tur çalışınca yine iki paket, yine bir sonraki tur. */
        (new GatewayPingRoundJob)->handle(app(GatewayPing::class));
        Queue::assertPushed(PingGatewayJob::class, 4);

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

        Queue::assertPushed(PingGatewayJob::class, fn (PingGatewayJob $is): bool => $is->targets === [['id' => $blok->id, 'gateway' => '198.51.100.1']]
            /* ↻ turun arkasında beklemez: işçinin önce işlediği kuyrukta. */
            && $is->queue === PingGatewayJob::NOW);
    }

    /** Betik: JSON ile atar, sonucu ayrı uçtan bekler; o uç da "ping" yetkisi ister. */
    #[Test]
    public function betik_sonucu_bekler(): void
    {
        $blok = $this->adres('198.51.100.0/24');
        $pingci = $this->izleyici(['ping']);

        $this->actingAs($this->izleyici())->getJson(route('ip-addresses.ping.show', $blok))->assertForbidden();
        $this->actingAs($pingci)->getJson(route('ip-addresses.ping.show', $blok))
            ->assertExactJson(['checkedAt' => null, 'variant' => null, 'label' => null]);

        Process::fake(fn () => Process::result(exitCode: 0));
        $this->actingAs($pingci)->postJson(route('ip-addresses.ping', $blok))
            ->assertOk()->assertJson(['checkedAt' => null]);

        $this->actingAs($pingci)->getJson(route('ip-addresses.ping.show', $blok))
            ->assertJson(['variant' => 'green'])
            ->assertJsonPath('checkedAt', $blok->refresh()->gateway_checked_at->toIso8601String());

        $this->actingAs($pingci)->postJson(route('ip-addresses.ping', $this->adres('198.51.100.7')))
            ->assertStatus(422)->assertJson(['message' => '198.51.100.7 için pinglenecek gateway yok.']);
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
            ->assertSeeInOrder(['198.51.100.0/24', '%50 boşta'])
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
