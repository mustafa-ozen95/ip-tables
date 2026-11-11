<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GroupKind;
use App\Enums\PermissionEffect;
use App\Models\ActivityLog;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\IpAddressPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Excel çıktısı: ayrı yetki ("Excel çıktısı al"), yalnız o yetkinin olduğu
 * gruplar dosyaya girer, çıktı işlem geçmişine düşer.
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737).
 */
class AddressExportTest extends TestCase
{
    use RefreshDatabase;

    private AddressGroup $acik;

    private AddressGroup $kapali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acik = AddressGroup::factory()->create(['name' => 'Grup A', 'kind' => GroupKind::Mixed]);
        $this->kapali = AddressGroup::factory()->create(['name' => 'Grup B', 'kind' => GroupKind::Mixed]);

        IpAddress::factory()->for($this->acik, 'group')->address('192.0.2.0/24')->create(['name' => 'blok-a']);
        IpAddress::factory()->for($this->acik, 'group')->address('192.0.2.8/29')->create(['name' => 'parca-a', 'description' => 'hat 1']);
        IpAddress::factory()->for($this->kapali, 'group')->address('198.51.100.0/24')->create(['name' => 'blok-b']);
    }

    /** @param  array<int, array{0: AddressGroup, 1: string}>  $yetkiler */
    private function kullanici(array $yetkiler): User
    {
        $kullanici = User::factory()->create();

        foreach ($yetkiler as [$grup, $aksiyon]) {
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

    private function sayfa(TestResponse $yanit): string
    {
        $yol = tempnam(sys_get_temp_dir(), 'ipt-test-');
        file_put_contents($yol, $yanit->getContent());
        $zip = new ZipArchive;
        $zip->open($yol);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($yol);

        return $xml;
    }

    #[Test]
    public function gorme_yetkisi_cikti_almaya_yetmez(): void
    {
        $kullanici = $this->kullanici([[$this->acik, 'view']]);

        $this->actingAs($kullanici)->get(route('ip-addresses.index'))
            ->assertOk()->assertDontSee(route('ip-addresses.export'));

        $this->actingAs($kullanici)->post(route('ip-addresses.export'))->assertForbidden();
        $this->assertSame(0, ActivityLog::query()->where('action', 'exported')->count());
    }

    #[Test]
    public function yalniz_cikti_yetkisi_olan_grup_dosyaya_girer(): void
    {
        $kullanici = $this->kullanici([[$this->acik, 'view'], [$this->acik, 'export'], [$this->kapali, 'view']]);

        $this->actingAs($kullanici)->get(route('ip-addresses.index'))
            ->assertSee(route('ip-addresses.export'));

        $yanit = $this->actingAs($kullanici)->post(route('ip-addresses.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment; filename="ip-adresleri-', (string) $yanit->headers->get('Content-Disposition'));

        $xml = $this->sayfa($yanit);

        /* Blok, altında parçası (geçit, ilk, son, maske) ve bölünmemiş alan. */
        foreach (['Ağ geçidi', '192.0.2.0/24', 'blok-a', '192.0.2.8/29', '192.0.2.9', '192.0.2.10', '192.0.2.14', '255.255.255.248', 'parca-a', 'hat 1', 'Bölünmemiş'] as $beklenen) {
            $this->assertStringContainsString('>'.$beklenen.'<', $xml);
        }
        $this->assertStringNotContainsString('198.51.100.0/24', $xml);
        $this->assertStringNotContainsString('blok-b', $xml);

        $this->assertSame(1, ActivityLog::query()->where('action', 'exported')
            ->where('summary', 'Excel çıktısı alındı: Hepsi · 2 kayıt')->count());
    }

    #[Test]
    public function grup_ve_arama_suzgeci_listedekiyle_ayni(): void
    {
        $kullanici = $this->kullanici([[$this->acik, 'view'], [$this->acik, 'export']]);

        $xml = $this->sayfa($this->actingAs($kullanici)
            ->post(route('ip-addresses.export'), ['grup' => $this->acik->uuid, 'q' => 'parca'])->assertOk());

        $this->assertStringContainsString('>parca-a<', $xml);
        $this->assertStringNotContainsString('>blok-a<', $xml);
        $this->assertSame(1, ActivityLog::query()->where('summary', 'Excel çıktısı alındı: Grup A · "parca" araması · 1 kayıt')->count());
    }
}
