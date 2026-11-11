<?php

declare(strict_types=1);

use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AddressGroupController;
use App\Http\Controllers\AddressSplitController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\CenterLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\GatewayPingController;
use App\Http\Controllers\IpAddressController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubGroupController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionController;
use Illuminate\Support\Facades\Route;

/*
 * Giriş ekranı. Oturum açmamış kullanıcının görebildiği TEK ekran budur;
 * kayıt olma ucu yoktur — hesabı yönetici açar.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    /*
     * Giriş hız sınırı KAPATILABİLİR: LOGIN_THROTTLE=0 verildiğinde ara katman
     * hiç eklenmez. `throttle:0,1` yazmak sınırı kaldırmaz, dakikada SIFIR
     * isteğe indirir ve girişi tümüyle kapatırdı.
     *
     * BU TEK FREN DEĞİLDİR. İkincisi LoginRequest'te: art arda yanlış
     * paroladan sonra e-posta+IP başına kilit (config/auth.php,
     * max_attempts). İkisi ayrı şeyi sayar, ikisi de ayardan kapatılır ve
     * varsayılanda ikisi de KAPALIDIR.
     *
     * İkisi birden kapalıyken bu panelde parola denemesinin önünde hiçbir
     * fren kalmaz; koruma dışarıdan gelmelidir (ters vekil, güvenlik duvarı,
     * ağın kendisi). Dışarı açılacaksa ikisine de bir sayı verin.
     */
    $girisRotasi = Route::post('login', [LoginController::class, 'store']);

    if ((int) config('auth.login_throttle') > 0) {
        $girisRotasi->middleware('throttle:'.config('auth.login_throttle').',1');
    }

    /*
     * Kimlik Yönetim Merkezi ile giriş — İKİNCİ yol, tek yol değil.
     *
     * Parolayla giriş yukarıda yerinde duruyor; merkez erişilemez olduğunda
     * da panele girilebilmeli.
     * Kapalı kurulumda controller 404 döner.
     *
     * Dönüş ucu GET ve gövdesiz olmak zorunda: kullanıcı merkezden buraya
     * tarayıcı yönlendirmesiyle gelir, POST edemez. CSRF'in yerini oturumda
     * saklanan `state` tutar (bkz. CenterLoginController).
     */
    Route::get('login/center', [CenterLoginController::class, 'start'])->name('identity.login');
    Route::get('login/center/callback', [CenterLoginController::class, 'callback'])->name('identity.callback');
});

/*
 * Bunun altındaki her şey oturum ister. "Giriş yapmayan adres göremez"
 * kuralı tek bir yerde, burada duruyor: yeni bir uç eklendiğinde bu grubun
 * dışına düşmesi gerekir ve o zaman denetim betiği onu yakalar.
 */
Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/addresses');

    /*
     * Ekleme ve düzenleme formunun AYRI SAYFASI YOKTUR: ikisi de liste
     * ekranının üstünde pencere olarak açılır (?pencere=ekle / ?pencere=duzenle).
     */
    /* /24 bloğu böl / bölmeyi kaldır, parçayı birleştir / parçala (bkz. Ipv4BlockRules). */
    Route::controller(AddressSplitController::class)->group(function (): void {
        Route::post('addresses/{ipAddress}/split', 'store')->name('ip-addresses.split');
        Route::delete('addresses/{ipAddress}/split', 'destroy')->name('ip-addresses.unsplit');
        Route::post('addresses/{ipAddress}/merge', 'merge')->name('ip-addresses.merge');
        Route::post('addresses/{ipAddress}/subdivide', 'subdivide')->name('ip-addresses.subdivide');
    });

    /* ↻ gateway'i şimdi pingle: dışarıya paket çıkar, sıklığı sınırlı. */
    Route::post('addresses/{ipAddress}/ping', [GatewayPingController::class, 'store'])
        ->middleware('throttle:30,1')->name('ip-addresses.ping');
    /* ↻'nin dönen ikonu sonucu buradan bekler (aralıkla sorar). */
    Route::get('addresses/{ipAddress}/ping', [GatewayPingController::class, 'show'])
        ->middleware('throttle:120,1')->name('ip-addresses.ping.show');

    Route::resource('addresses', IpAddressController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['addresses' => 'ipAddress'])
        ->names('ip-addresses');

    Route::prefix('panel')->group(function (): void {
        /* Sürükleyerek sıralama; kaynağın PUT {group} rotasından ÖNCE, "sira" bir grup adı sanılmasın. */
        Route::put('groups/order', [AddressGroupController::class, 'reorder'])->name('groups.reorder');

        Route::resource('groups', AddressGroupController::class)
            ->parameters(['groups' => 'group'])
            ->names('groups')
            ->except('show');

        /*
         * ALT GRUPLAR grubun düzenleme sayfasından yönetilir; kendi listesi
         * yok. Bölme grubun İÇİNDEDİR, adres de öyle söyler.
         */
        Route::post('groups/{group}/sub-groups', [SubGroupController::class, 'store'])->name('groups.sub.store');
        Route::put('groups/{group}/sub-groups/order', [SubGroupController::class, 'reorder'])->name('groups.sub.reorder');

        /* Alt grup grubun İÇİNDEN çözülür: başka grubun alt grubu bu adresle 404'tür. */
        Route::scopeBindings()->group(function (): void {
            Route::put('groups/{group}/sub-groups/{subGroup}', [SubGroupController::class, 'update'])->name('groups.sub.update');
            Route::delete('groups/{group}/sub-groups/{subGroup}', [SubGroupController::class, 'destroy'])->name('groups.sub.destroy');
        });

        /*
         * SERTİFİKA. Tur konteyner dışındaki sertifika ajanında koşar; panel
         * isteği bırakır ve sonucu okur (bkz. CertificateController).
         */
        Route::get('settings/certificate', [CertificateController::class, 'index'])->name('certificate.index');
        Route::post('settings/certificate/run', [CertificateController::class, 'start'])->name('certificate.start');
        Route::post('settings/certificate/confirm', [CertificateController::class, 'complete'])->name('certificate.complete');

        /*
         * VERİTABANI. Panel bağlantıyı sınar ve taşıma isteğini bırakır;
         * kopya yeniden başlatmada, panel kapalıyken yapılır
         * (bkz. DatabaseController ve ConnectionFile).
         */
        Route::get('settings/database', [DatabaseController::class, 'index'])->name('database.index');
        Route::post('settings/database/test', [DatabaseController::class, 'test'])->name('database.test');
        Route::post('settings/database/migrate', [DatabaseController::class, 'migrate'])->name('database.migrate');

        /*
         * GÜNCELLEME. Çalışan sürüm GitHub'daki son başarılı derlemeyle
         * karşılaştırılır (bkz. UpdateCheck); yeni sürüm varsa apply platformun
         * deploy hook'unu tetikler (bkz. UpdateApply).
         */
        Route::get('settings/update', [UpdateController::class, 'index'])->name('update.index');
        Route::post('settings/update', [UpdateController::class, 'check'])->name('update.check');
        Route::post('settings/update/apply', [UpdateController::class, 'apply'])->name('update.apply');

        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

        /*
         * API jetonları — Ayarlar'ın bir sekmesi.
         *
         * Jeton, sahibinin yetkisini kalıcı bir anahtara dönüştürür; bu yüzden
         * yönetimi yalnızca yöneticidedir (bkz. ApiTokenPolicy).
         */
        Route::controller(ApiTokenController::class)->prefix('api-tokens')->name('api-tokens.')->group(function (): void {
            Route::get('/', 'index')->name('index');

            /* Belge {apiToken}'dan ÖNCE: yoksa "belge" bir uuid sanılırdı. */
            Route::get('docs', 'documentation')->name('docs');

            Route::post('/', 'store')->name('store');
            Route::get('{apiToken}', 'show')->name('show');
            Route::put('{apiToken}', 'update')->name('update');
            Route::put('{apiToken}/secret', 'rotate')->name('rotate');
            Route::put('{apiToken}/revoke', 'revoke')->name('revoke');
            Route::delete('{apiToken}', 'destroy')->name('destroy');
        });

        Route::resource('roles', RoleController::class)
            ->parameters(['roles' => 'role'])
            ->names('roles')
            ->except('show');

        Route::resource('users', UserController::class)
            ->parameters(['users' => 'user'])
            ->names('users')
            ->except('show');

        Route::get('users/{user}/permissions', [UserPermissionController::class, 'edit'])
            ->name('users.permissions.edit');
        Route::put('users/{user}/permissions', [UserPermissionController::class, 'update'])
            ->name('users.permissions.update');

        Route::get('logs', [ActivityLogController::class, 'index'])->name('logs.index');
        Route::post('logs/verify', [ActivityLogController::class, 'verify'])->name('logs.verify');

        Route::get('errors', [ErrorLogController::class, 'index'])->name('errors.index');
        Route::post('errors/{error}/resolved', [ErrorLogController::class, 'resolve'])->name('errors.resolve');
    });
});

/*
 * Hesabım: herkes yalnızca KENDİ hesabını düzenler.
 *
 * Rol, durum ve grup izinleri burada değişmez — onlar yöneticinin ekranında
 * durur. "Kendi profilini düzenle" yetkisi, kendini yönetici yapma yetkisi
 * değildir.
 */
Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.save');
    Route::put('password', [ProfileController::class, 'password'])->name('password');
});
