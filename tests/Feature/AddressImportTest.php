<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * addresses:import: sekmeyle ayrılmış listeden toplu yükleme. Önce önizler,
 * tek hata varsa hiçbir şey yazmaz; --hepsini-sil bütün adresleri siler.
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849).
 */
class AddressImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dosya;

    private User $kullanici;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dosya = storage_path('framework/testing/iceri-aktarma-testi.tsv');
        @mkdir(dirname($this->dosya), 0775, true);
        $this->kullanici = User::factory()->create(['email' => 'yukleyen@ornek.test', 'name' => 'Yükleyen']);
    }

    protected function tearDown(): void
    {
        @unlink($this->dosya);

        parent::tearDown();
    }

    /** @param  list<list<string>>  $satirlar */
    private function liste(array $satirlar): void
    {
        file_put_contents($this->dosya, "grup\talt grup\tadres\tad\taçıklama\tvurgu\n"
            .implode("\n", array_map(fn (array $s): string => implode("\t", $s), $satirlar))."\n");
    }

    /** @param  array<string, mixed>  $secenek */
    private function yukle(array $secenek = [])
    {
        return $this->artisan('addresses:import', ['dosya' => $this->dosya, '--kullanici' => 'yukleyen@ornek.test', ...$secenek]);
    }

    private function ipv4(): AddressGroup
    {
        return AddressGroup::query()->where('kind', GroupKind::Ipv4)->firstOrFail();
    }

    #[Test]
    public function onizleme_yazmaz(): void
    {
        $this->liste([['IPv4', '198.51.100.0/22', '198.51.100.0/24', 'Müşteri A', '', '']]);

        $this->yukle()->expectsOutputToContain('Önizleme')->assertSuccessful();

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    #[Test]
    public function hata_varsa_hicbir_sey_yazilmaz(): void
    {
        $this->liste([
            ['IPv4', '', '198.51.100.0/24', 'iyi', '', ''],
            ['IPv4', '', '198.51.100.0/22', 'büyük', '', ''],
            ['Olmayan', '', '203.0.113.0/24', '', '', ''],
            ['IPv4', '', '2001:db8::/64', '', '', ''],
            ['IPv4', '', '192.0.2.0/24', '', '', 'pembe'],
            ['IPv4', '', '198.51.100.0/24', 'yine', '', ''],
        ]);

        $this->yukle(['--yaz' => true])
            ->expectsOutputToContain("Satır 3: 198.51.100.0/22: IPv4'te en büyük blok /24.")
            ->expectsOutputToContain('Satır 4: "Olmayan" adlı grup yok')
            ->expectsOutputToContain('Satır 5: 2001:db8::/64 IPv4 grubuna uymuyor')
            ->expectsOutputToContain('Satır 6: vurgu "pembe" bilinmiyor.')
            ->expectsOutputToContain('Satır 7: 198.51.100.0/24 listede iki kez var.')
            ->assertFailed();

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    #[Test]
    public function yazar_alt_grup_vurgu_ve_parca(): void
    {
        $this->liste([
            ['IPv4', '198.51.100.0/22', '198.51.100.0/24', 'Müşteri A', '', ''],
            ['IPv4', '198.51.100.0/22', '198.51.100.1/24', 'SUNUCU KULLANMAKTA', 'yedek sunucu', 'kırmızı'],
            ['IPv4', '198.51.100.0/22', '203.0.113.0/25', 'Müşteri B', '', ''],
            ['IPv4', '', '192.0.2.0/24', '', '', ''],
        ]);

        $this->yukle(['--yaz' => true])->expectsOutputToContain('0 kayıt silindi, 4 kayıt eklendi.')->assertSuccessful();

        $vurgulu = IpAddress::query()->where('address', '198.51.100.1')->first();
        $this->assertSame(['198.51.100.0/22', 'red', 'yedek sunucu'], [$vurgulu->subGroup->name, $vurgulu->highlight->value, $vurgulu->description]);
        $this->assertTrue($vurgulu->group->is($this->ipv4()));
        $this->assertNull(IpAddress::query()->where('address', '192.0.2.0')->first()->subGroup);

        /* /25 kendi /24'ünün parçası olur; blok adsız açılır. */
        $blok = IpAddress::query()->where('address', '203.0.113.0')->where('prefix', 24)->first();
        $parca = IpAddress::query()->where('address', '203.0.113.0')->where('prefix', 25)->first();
        $this->assertNull($blok->name);
        $this->assertSame(['Müşteri B', '198.51.100.0/22'], [$parca->name, $parca->subGroup->name]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'created', 'actor_name' => 'Yükleyen']);
    }

    #[Test]
    public function hepsini_sil_once_siler(): void
    {
        $eski = IpAddress::factory()->for($this->ipv4(), 'group')->address('192.0.2.0/24')->create(['name' => 'eski']);
        $this->liste([['IPv4', '', '192.0.2.0/24', 'yeni', '', '']]);

        $this->yukle(['--yaz' => true])->expectsOutputToContain('192.0.2.0/24 IPv4 grubunda zaten kayıtlı.')->assertFailed();

        $this->yukle(['--yaz' => true, '--hepsini-sil' => true])->expectsOutputToContain('1 kayıt silindi, 1 kayıt eklendi.')->assertSuccessful();

        $this->assertModelMissing($eski);
        $this->assertSame(['yeni'], IpAddress::query()->pluck('name')->all());
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted', 'reason' => 'Toplu yükleme: liste yeniden işlendi']);
    }

    #[Test]
    public function kullanici_zorunlu(): void
    {
        $this->liste([['IPv4', '', '192.0.2.0/24', '', '', '']]);

        $this->artisan('addresses:import', ['dosya' => $this->dosya])->assertFailed();
        $this->artisan('addresses:import', ['dosya' => $this->dosya, '--kullanici' => 'yok@ornek.test', '--yaz' => true])->assertFailed();

        $this->assertDatabaseCount('ip_addresses', 0);
    }
}
