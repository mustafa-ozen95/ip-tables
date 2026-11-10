<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GroupKind;
use App\Enums\UserStatus;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\SubGroup;
use App\Models\User;
use App\Services\IpAddressParser;
use Illuminate\Database\Seeder;

/**
 * GÖRSEL TEST veritabanının kurulumu.
 *
 * Playwright senaryoları belirli kayıtları ADIYLA arıyor ("web-01",
 * "Müşteri B"). Kurulum burada durur ki sqlite dosyası kaybolunca dünya
 * yeniden aynı kurulsun.
 *
 * Adresler belgeleme aralıklarındandır (RFC 5737 / 3849) ve yerel
 * aralıkların uydurma örnekleridir; hiçbiri gerçek bir ağı anlatmaz.
 *
 * ÜRETİMDE ÇALIŞTIRILMAZ: `DatabaseSeeder`'a bağlı değildir, elle çağrılır.
 */
class VisualTestSeeder extends Seeder
{
    public function run(): void
    {
        /* Bilinen bir parolayla yönetici açar; üretimde kazara bile koşmamalı. */
        abort_if(app()->isProduction(), 500, 'VisualTestSeeder üretimde çalıştırılmaz.');

        $yoneticiRol = Role::where('is_admin', true)->firstOrFail();
        $sadeRol = Role::where('is_admin', false)->where('is_default', true)->firstOrFail();

        User::updateOrCreate(['email' => 'gorsel@ornek.test'], [
            'name' => 'Görsel Test',
            'password' => 'gorsel-test-1616',
            'role_id' => $yoneticiRol->id,
            'status' => UserStatus::Active,
        ]);

        /* Yetkisiz görünümü sınayan senaryolar için: yönetici OLMAYAN hesap. */
        User::updateOrCreate(['email' => 'kisitli@ornek.test'], [
            'name' => 'Kısıtlı Kullanıcı',
            'password' => 'gorsel-test-1616',
            'role_id' => $sadeRol->id,
            'status' => UserStatus::Active,
        ]);

        $ipv4 = AddressGroup::where('kind', GroupKind::Ipv4)->firstOrFail();
        $ipv6 = AddressGroup::where('kind', GroupKind::Ipv6)->firstOrFail();
        $yerel4 = AddressGroup::where('kind', GroupKind::LocalIpv4)->firstOrFail();
        $yerel6 = AddressGroup::where('kind', GroupKind::LocalIpv6)->firstOrFail();

        $this->adres($ipv4, '192.0.2.10', 'web-01', 'Ön yüz sunucusu');
        $this->adres($ipv4, '192.0.2.11', 'web-02');
        $this->adres($ipv4, '198.51.100.0/24', 'Yedek blok');
        $this->adres($ipv6, '2001:db8::10', 'web-01');
        $this->adres($ipv6, '2001:db8:100::/48', 'Müşteri bloğu');
        $this->adres($yerel4, '10.20.0.1', 'Ağ geçidi');
        $this->adres($yerel4, '192.168.10.0/24', 'Ofis');
        $this->adres($yerel6, 'fd00:10::1', 'Ağ geçidi');

        /* Kullanıcının açtığı karışık grup ve bölmeleri. */
        $musteri = AddressGroup::firstOrCreate(['name' => 'Müşteri B'], [
            'kind' => GroupKind::Mixed,
            'description' => 'Karışık grup: her türden adres girer.',
            'position' => 90,
        ]);
        $dmz = SubGroup::firstOrCreate(['address_group_id' => $musteri->id, 'name' => 'DMZ'], ['position' => 1]);

        $this->adres($musteri, '203.0.113.5', 'vpn', null, $dmz);
        $this->adres($musteri, '2001:db8:200::5', 'vpn', null, $dmz);
        $this->adres($musteri, '172.16.5.0/24', 'İç ağ');
    }

    private function adres(AddressGroup $grup, string $girdi, string $ad, ?string $aciklama = null, ?SubGroup $bolme = null): void
    {
        $parsed = app(IpAddressParser::class)->parse($girdi);

        $ip = IpAddress::firstOrNew([
            'address_group_id' => $grup->id,
            'address' => $parsed->address,
            'prefix' => $parsed->prefix,
        ]);

        $ip->forceFill([
            'sort_key' => $parsed->sortKey,
            'sub_group_id' => $bolme?->id,
            'name' => $ad,
            'description' => $aciklama,
        ])->save();
    }
}
