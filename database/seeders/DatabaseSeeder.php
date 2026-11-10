<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Kurulumun tohumu. Yerleşik roller ve dört liste grubu göçlerle gelir;
 * ilk yönetici ortamdan okunur.
 *
 * VERİ DEPODA DURMAZ: adres planı panelden ya da API'den girilir. Buraya
 * gerçek bir adres yazmak onu herkese açık depoya koymak olurdu.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);
    }
}
