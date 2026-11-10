<?php

declare(strict_types=1);

use App\Http\Controllers\Api\IdentityLogoutController;
use App\Http\Controllers\Api\IdentityRefreshController;
use App\Http\Controllers\Api\IpAddressApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
| Kimlik doğrulaması yalnızca jetonladır ("auth:api"); oturum çerezi kabul
| edilmez. Jeton sahibi adına konuşur ve onu AŞAMAZ: yetki hem jetonun kendi
| listesinden hem de sahibinin panel yetkilerinden geçer
| (bkz. TokenAbilityGate). Grup bazlı istisnalar da aynen geçerlidir — bir
| jeton, sahibinin göremediği grubu okuyamaz.
|
| NEDEN VAR: adres planını başka sistemler de (güvenlik duvarı kuralları,
| izleme, envanter) okur. Elle kopyalanan liste sessizce eskir; sistem
| listeyi buradan çekerse tek kaynak burası kalır.
|
| Sürüm adresin parçasıdır: yarın kırıcı bir değişiklik gerekirse "v2" açılır
| ve eski istemciler kırılmaz.
|
| ADRESLER UUID TAŞIR ve rota kalıbı bunu ZORUNLU kılar: sıralı birincil
| anahtar dışarı çıkmaz, "1, 2, 3 diye deneyeyim" yolu baştan kapalıdır.
| Kayıt ayrıca GÖRÜNÜR kümenin içinden çözülür; göremediği uuid 404 alır,
| çünkü 403 "bu kayıt var" demenin başka bir yolu olurdu.
|
| Hız sınırı JETON başınadır, IP başına değil: aynı IP'den gelen iki jeton
| birbirini yavaşlatmamalı. Bunun için sınır kimlik doğrulamasından SONRA
| uygulanır — jeton ancak o zaman bilinir.
|
| Kimliği DOĞRULANAMAYAN istek ise ThrottleApiClients ile, "api" grubunda
| sınırlanır. Buraya "throttle:..." yazmak İŞE YARAMAZ: Laravel ara katmanları
| önceliğe göre sıralıyor ve Authenticate, ThrottleRequests'ten önce geliyor —
| listede önüne yazılsa bile auth önce çalışıp isteği 401 ile bitiriyor.
*/

Route::middleware(['auth:api', 'throttle:api'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('addresses', [IpAddressApiController::class, 'index'])->name('ip-addresses.index');
        Route::get('addresses/{ipAddress}', [IpAddressApiController::class, 'show'])
            ->whereUuid('ipAddress')
            ->name('ip-addresses.show');
    });

/*
 * MERKEZDEN GELEN "bu kimlik değişti" bildirimi.
 *
 * Jeton gerektirmez: kimlik doğrulaması istek gövdesinin HMAC imzasıdır
 * (istemci sırrıyla). Gelen veriye güvenilmez; panel doğruyu merkezden kendi
 * sorar (bkz. IdentityRefreshController).
 *
 * Hız sınırı var: imza tutmasa bile bu uç merkeze bir istek attırabilir.
 */
Route::post('identity/refresh', IdentityRefreshController::class)
    ->middleware('throttle:60,1')
    ->name('api.identity.refresh');

/* MERKEZDEN "bu kişi çıkış yaptı" — ortak çıkış (bkz. IdentityLogoutController). */
Route::post('identity/logout', IdentityLogoutController::class)
    ->middleware('throttle:60,1')
    ->name('api.identity.logout');
