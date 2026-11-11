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

/** Vurgu rengi: ad listede kalın ve seçilen renkte. */
class HighlightTest extends TestCase
{
    use RefreshDatabase;

    private AddressGroup $grup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grup = AddressGroup::query()->where('kind', GroupKind::Ipv4)->firstOrFail();
    }

    private function yetkili(): User
    {
        $kullanici = User::factory()->create();

        foreach (['view', 'create', 'update'] as $yetki) {
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

    #[Test]
    public function formdan_kaydedilir_ve_listede_renkli(): void
    {
        $kullanici = $this->yetkili();

        $this->actingAs($kullanici)
            ->post(route('ip-addresses.store'), ['group' => $this->grup->uuid, 'address' => '192.0.2.0/24', 'name' => 'ROUTER KULLANMAKTA', 'highlight' => 'red'])
            ->assertSessionHasNoErrors();
        $this->actingAs($kullanici)
            ->post(route('ip-addresses.store'), ['group' => $this->grup->uuid, 'address' => '198.51.100.0/24', 'name' => 'düz', 'highlight' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('red', IpAddress::query()->where('address', '192.0.2.0')->first()->highlight->value);
        $this->assertNull(IpAddress::query()->where('address', '198.51.100.0')->first()->highlight);

        $this->actingAs($kullanici)->get(route('ip-addresses.index', ['grup' => $this->grup->uuid]))
            ->assertOk()
            ->assertSee('<strong class="highlight highlight--red">ROUTER KULLANMAKTA</strong>', false)
            ->assertDontSee('highlight--red">düz', false)
            ->assertSee('Vurgu rengi');
    }

    #[Test]
    public function bilinmeyen_renk_reddedilir(): void
    {
        $this->actingAs($this->yetkili())
            ->post(route('ip-addresses.store'), ['group' => $this->grup->uuid, 'address' => '192.0.2.0/24', 'highlight' => 'pembe'])
            ->assertSessionHasErrors('highlight');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    /** Renk açılır listede şerit olarak görünür (highlight-select); kaydın rengi seçili gelir. */
    #[Test]
    public function renkler_gorunerek_secilir(): void
    {
        $kayit = IpAddress::factory()->for($this->grup, 'group')->address('192.0.2.0/24')->create(['name' => 'blok', 'highlight' => 'green']);
        $html = preg_replace('/\s+/', ' ', $this->actingAs($this->yetkili())
            ->get(route('ip-addresses.index', ['grup' => $this->grup->uuid, 'kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/<select class="select highlight-select" name="highlight"/', $html);
        $this->assertStringContainsString('<option value="green" selected>Yeşil</option>', $html);
        $this->assertStringNotContainsString('value="red" selected', $html);
    }
}
