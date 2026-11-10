<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Güvenlik başlıkları.
 *
 * nginx'te değil uygulamada durur: vekil değişince başlıkların kaybolmaması
 * için. Değerler config/security.php içindedir.
 *
 * Başlıklar olmasa herhangi bir site paneli görünmez bir çerçeveye alıp
 * üstüne kendi düğmesini koyabilir, kullanıcı ona bastığında tıklama
 * paneldeki düğmeye gidebilirdi: "sil" ya da "kaydet".
 *
 * VAR OLAN BAŞLIK EZİLMEZ: bir yanıt kendi kararını yazdıysa burada
 * gevşetmek, o kararı sessizce geri almak olur.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $yanit = $next($request);
        foreach ((array) config('security.headers') as $ad => $deger) {
            if (blank($deger) || $yanit->headers->has($ad)) {
                continue;
            }

            $yanit->headers->set($ad, $deger);
        }

        $this->onbellekKapat($request, $yanit);

        return $yanit;
    }

    /**
     * OTURUM AÇIK SAYFALAR DİSKE YAZILMAZ.
     *
     * İki sebep, ikisi de gerçek:
     *
     * 1. GÜVENLİK. Sayfada iç ağın adres planı duruyor.
     *    `no-cache` tarayıcıya "kullanmadan önce sor" der ama KOPYAYI
     *    DİSKTEN SİLMEZ; ortak bir makinede çıkış yapıldıktan sonra da o
     *    dosya orada kalır. `no-store` hiç yazmamasını söyler.
     *
     * 2. ESKİ SAYFA. `no-cache` geri/ileri önbelleğini (bfcache) kapatmaz:
     *    kullanıcı geri gidip geldiğinde tarayıcı sayfayı SUNUCUYA HİÇ
     *    SORMADAN bellekten çizer. Panelde bir düğme eklendiğinde kullanıcı
     *    onu göremez ve haklı olarak "koymamışsın" der — sunucu koymuştur.
     *    `no-store` bu yolu da kapatır.
     *
     * YALNIZCA HTML ve yalnızca OTURUM AÇIKKEN: varlıklar (css/js) uzun
     * ömürlü önbelleğe muhtaç, giriş sayfasının da saklanacak bir şeyi yok.
     *
     * Var olan başlık yine ezilmez (bkz. yukarısı).
     */
    private function onbellekKapat(Request $request, Response $yanit): void
    {
        /*
         * "Zaten var mı" diye BAKILMAZ: Laravel oturumlu her yanıta
         * kendiliğinden `no-cache, private` yazıyor, yani başlık her zaman
         * dolu olurdu ve bu kural hiç çalışmazdı. Bakılacak olan, yanıtın
         * BİLEREK no-store demiş olmasıdır — o zaman dokunulmaz.
         */
        if ($request->user() === null) {
            return;
        }

        if (str_contains((string) $yanit->headers->get('Cache-Control', ''), 'no-store')) {
            return;
        }

        $tur = (string) $yanit->headers->get('Content-Type', '');

        if (! str_contains($tur, 'text/html')) {
            return;
        }

        $yanit->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $yanit->headers->set('Pragma', 'no-cache');
    }
}
