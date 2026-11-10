<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tarayıcıya gönderilen güvenlik başlıkları.
 *
 * Başlıklar olmasa panel görünmez bir çerçeveye alınıp kullanıcının
 * tıklaması "sil" düğmesine yönlendirilebilirdi. Panel hiçbir şeyi
 * çerçeveye almaz ve kendisi de alınamaz.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function panel_sayfasi_cerceveye_alinamaz(): void
    {
        $yanit = $this->get(route('login'));

        $yanit->assertOk();
        $yanit->assertHeader('X-Frame-Options', 'DENY');
        $csp = (string) $yanit->headers->get('Content-Security-Policy');

        /* Satır içi script ve style YOK; politikanın anlamı buradan geliyor. */
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("frame-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $yanit->assertHeader('X-Content-Type-Options', 'nosniff');
        $yanit->assertHeader('Referrer-Policy', 'same-origin');
    }

    /**
     * OTURUMSUZ İSTEĞİN YÖNLENDİRMESİ DE BAŞLIK TAŞIR.
     *
     * Ara katman önce "web" grubuna eklenmişti ve en içte kalıyordu: kimlik
     * doğrulaması düşünce fırlatılan istisna oradan yukarı geçiyor, yanıtı
     * istisna işleyicisi kuruyor ve o yanıt bir daha ara katmandan geçmiyordu.
     * Sonuç: giriş yönlendirmesi ve bütün hata sayfaları çıplaktı. Artık genel
     * yığında.
     */
    #[Test]
    public function oturumsuz_istegin_yonlendirmesi_de_baslik_tasir(): void
    {
        $yanit = $this->get(route('ip-addresses.index'));

        $yanit->assertRedirect();
        $yanit->assertHeader('X-Frame-Options', 'DENY');
        $yanit->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
