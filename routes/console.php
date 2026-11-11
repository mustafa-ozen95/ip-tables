<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Zamanlanmış işler
|--------------------------------------------------------------------------
| Merkezdeki rol/durum değişikliğini yerele taşıyan tur ve sürüm kontrolü
| doğası gereği periyodiktir; elle çalıştırıldıklarında unutuluyorlar.
| Panelde gezinen kişi için kimlik eşitlemesini ara katman zaten yapıyor
| (VerifyIdentityStatus), ama panele hiç uğramayan bir hesap merkezde askıya
| alındığında burada açık kalıyordu.
|
| Konteynerde zamanlayıcıyı supervisord çalıştırır (schedule:work). Konteyner
| dışında TEK BİR CRON SATIRI GEREKİR (site kullanıcısı, root DEĞİL — aksi
| hâlde storage ve önbellek root'a geçer ve panel 500 verir):
|
|   * * * * * cd /kurulum/dizini/ip-tables && php artisan schedule:run >> /dev/null 2>&1
|
| Satır yokken buradaki tanımlar zararsızdır: hiçbir şey çalışmaz, komutlar
| elle çağrılmaya devam eder.
*/

/*
 * Merkezde erişimi kalkan kişi burada da kaybetsin. Beş dakika, "erişimi
 * kalkan biri en fazla ne kadar daha içeride kalabilir" sorusunun yanıtı ve
 * ara katmanın önbellek süresiyle aynı.
 */
Schedule::command('identity:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

/*
 * YENİ SÜRÜM VAR MI — HER SABAH.
 *
 * Yalnızca bakar ve sonucu yazar; yeni sürüm varsa yönetici panelin üstünde
 * uyarı görür. Güncellemenin kendisi sunucudan yapılır. Ayarlar →
 * Güncelleme'deki düğme aynı kontrolü hemen yapar.
 */
Schedule::command('update:check')->dailyAt('06:50');

/*
 * GATEWAY PING — ON DAKİKADA BİR.
 *
 * Bölünmüş blokta parçaların gateway'i, bölünmemiş /24'te .1 (bkz.
 * GatewayPing). Yanıt veren "kullanılıyor" sayılır; listede yeşil/kırmızı
 * nokta. Tur uzun sürerse yenisi üst üste binmez.
 */
Schedule::command('gateways:ping')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();
