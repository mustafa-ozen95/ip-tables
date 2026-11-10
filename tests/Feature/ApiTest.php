<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
use App\Enums\PermissionEffect;
use App\Models\AddressGroup;
use App\Models\ApiToken;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\IpAddressPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adres planının dışarıya açtığı salt okunur arayüz.
 *
 * Testlerin kilitlediği şey "okunabiliyor mu" DEĞİL, kimin neyi okuyabildiği:
 * jeton sahibini aşamamalı, göremediği adres onun için var olmamalı ve sıralı
 * birincil anahtar hiçbir yerden dışarı çıkmamalı.
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849).
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private const LISTE = '/api/v1/addresses';

    private function grup(string $ad, GroupKind $tur = GroupKind::Mixed): AddressGroup
    {
        return AddressGroup::factory()->create(['name' => $ad, 'kind' => $tur]);
    }

    private function adres(AddressGroup $grup, string $girdi, ?string $ad = null): IpAddress
    {
        return IpAddress::factory()->for($grup, 'group')->address($girdi)->create(['name' => $ad]);
    }

    /** @param  list<string>  $aksiyonlar */
    private function kullanici(AddressGroup $grup, array $aksiyonlar = ['view']): User
    {
        $kullanici = User::factory()->create();

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

    /**
     * @param  array<string, list<string>>  $yetkiler
     * @return array{0: ApiToken, 1: string}
     */
    private function jeton(User $sahip, array $yetkiler = [IpAddressPolicy::RESOURCE => ['view']]): array
    {
        $yeni = ApiToken::mint();

        $jeton = ApiToken::create([
            'name' => 'Güvenlik duvarı',
            'user_id' => $sahip->id,
            'token_hash' => $yeni['hash'],
            'prefix' => $yeni['prefix'],
            'abilities' => $yetkiler,
        ]);

        return [$jeton, $yeni['plain']];
    }

    /* ── Kapı ── */

    #[Test]
    public function jetonsuz_istek_reddedilir(): void
    {
        $this->getJson(self::LISTE)->assertUnauthorized();
    }

    #[Test]
    public function gecersiz_jeton_reddedilir(): void
    {
        $this->withToken('uydurma')->getJson(self::LISTE)->assertUnauthorized();
    }

    /** Oturum çerezi API'ye geçseydi tarayıcıdan gelen istek CSRF korumasının dışına çıkardı. */
    #[Test]
    public function oturum_cerezi_api_kapisini_acmaz(): void
    {
        $grup = $this->grup('Grup A');

        $this->actingAs($this->kullanici($grup))->getJson(self::LISTE)->assertUnauthorized();
    }

    #[Test]
    public function iptal_edilmis_jeton_calismaz(): void
    {
        [$jeton, $duz] = $this->jeton($this->kullanici($this->grup('Grup A')));

        $jeton->revoke();

        $this->withToken($duz)->getJson(self::LISTE)->assertUnauthorized();
    }

    #[Test]
    public function suresi_dolmus_jeton_calismaz(): void
    {
        [$jeton, $duz] = $this->jeton($this->kullanici($this->grup('Grup A')));

        $jeton->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($duz)->getJson(self::LISTE)->assertUnauthorized();
    }

    #[Test]
    public function pasif_sahibin_jetonu_calismaz(): void
    {
        $sahip = $this->kullanici($this->grup('Grup A'));
        [, $duz] = $this->jeton($sahip);

        $sahip->forceFill(['status' => 'passive'])->save();

        $this->withToken($duz)->getJson(self::LISTE)->assertUnauthorized();
    }

    #[Test]
    public function izinli_adres_disindan_gelen_jeton_calismaz(): void
    {
        [$jeton, $duz] = $this->jeton($this->kullanici($this->grup('Grup A')));

        $jeton->forceFill(['allowed_ips' => ['198.51.100.9']])->save();

        $this->withToken($duz)->getJson(self::LISTE)->assertUnauthorized();
    }

    /* ── Yetki ── */

    /** Sahibi görebiliyor ama jetonun listesinde "görüntüle" yok: jeton sahibini aşamaz. */
    #[Test]
    public function jeton_listesinde_olmayan_yetki_kullanilamaz(): void
    {
        $grup = $this->grup('Grup A');
        [, $duz] = $this->jeton($this->kullanici($grup, ['view', 'create']), [IpAddressPolicy::RESOURCE => ['create']]);

        $this->withToken($duz)->getJson(self::LISTE)->assertForbidden();
    }

    /** Jetonun listesi geniş olsa da sahibinin göremediği grup görünmez. */
    #[Test]
    public function jeton_sahibinin_gorebildigi_adresleri_listeler(): void
    {
        $acik = $this->grup('Grup A');
        $kapali = $this->grup('Grup B');
        $this->adres($acik, '192.0.2.10', 'web-01');
        $this->adres($kapali, '203.0.113.5', 'gizli-sunucu');

        [, $duz] = $this->jeton($this->kullanici($acik));

        $this->withToken($duz)->getJson(self::LISTE)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.adres', '192.0.2.10')
            ->assertJsonPath('data.0.grup.ad', 'Grup A')
            ->assertDontSee('203.0.113.5')
            ->assertDontSee('gizli-sunucu');
    }

    /** 403 "bu kayıt var" demenin başka bir yolu olurdu. */
    #[Test]
    public function gorulemeyen_adres_404_doner(): void
    {
        $acik = $this->grup('Grup A');
        $gizli = $this->adres($this->grup('Grup B'), '203.0.113.5');

        [, $duz] = $this->jeton($this->kullanici($acik));

        $this->withToken($duz)->getJson(self::LISTE."/{$gizli->uuid}")->assertNotFound();
    }

    #[Test]
    public function sirali_anahtarla_adreslenemez(): void
    {
        $grup = $this->grup('Grup A');
        $adres = $this->adres($grup, '192.0.2.10');

        [, $duz] = $this->jeton($this->kullanici($grup));

        $this->withToken($duz)->getJson(self::LISTE."/{$adres->id}")->assertNotFound();
    }

    #[Test]
    public function sirali_anahtar_yanitta_gecmez(): void
    {
        $grup = $this->grup('Grup A');
        $adres = $this->adres($grup, '192.0.2.10');

        [, $duz] = $this->jeton($this->kullanici($grup));

        $yanit = $this->withToken($duz)->getJson(self::LISTE."/{$adres->uuid}")->assertOk();

        $this->assertSame($adres->uuid, $yanit->json('data.kimlik'));
        $this->assertArrayNotHasKey('id', $yanit->json('data'));
        $this->assertArrayNotHasKey('address_group_id', $yanit->json('data'));
        $this->assertArrayNotHasKey('sort_key', $yanit->json('data'));
    }

    /* ── Süzgeç ── */

    #[Test]
    public function grup_tur_ve_metinle_suzulur(): void
    {
        $v4 = $this->grup('Dış IPv4', GroupKind::Ipv4);
        $v6 = $this->grup('Dış IPv6', GroupKind::Ipv6);
        $this->adres($v4, '192.0.2.10', 'web-01');
        $this->adres($v4, '192.0.2.11', 'db-01');
        $this->adres($v6, '2001:db8::10', 'web-01');

        [, $duz] = $this->jeton(User::factory()->teknik()->create());

        $this->withToken($duz)->getJson(self::LISTE.'?tur=ipv6')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.adres', '2001:db8::10');

        $this->withToken($duz)->getJson(self::LISTE."?grup={$v4->uuid}&q=web")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.adres', '192.0.2.10');

        $this->withToken($duz)->getJson(self::LISTE.'?tur=bilinmeyen')->assertUnprocessable();
    }

    /** Adres sırası metin değil sayıdır: .9, .10'dan önce gelir. */
    #[Test]
    public function adresler_sayisal_siralanir(): void
    {
        $grup = $this->grup('Grup A');
        $this->adres($grup, '192.0.2.10');
        $this->adres($grup, '192.0.2.9');
        $this->adres($grup, '192.0.2.100');

        [, $duz] = $this->jeton($this->kullanici($grup));

        $adresler = collect($this->withToken($duz)->getJson(self::LISTE)->json('data'))->pluck('adres')->all();

        $this->assertSame(['192.0.2.9', '192.0.2.10', '192.0.2.100'], $adresler);
    }
}
