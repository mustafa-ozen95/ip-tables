<?php

declare(strict_types=1);

use App\Models\AddressGroup;

/*
|--------------------------------------------------------------------------
| Yetki kaydı
|--------------------------------------------------------------------------
| Yetki GRUP seviyesinde verilir: rol şablonu "bütün gruplarda" ya da
| "şu gruplarda" der; kişiye özel istisnalar (izin/yasak) rolün üstüne
| yazılır (bkz. App\Services\Authorization\UserPermissionResolver).
|
| Yönetici rolü (is_admin) bütün kontrolleri geçer ve burada tanımlanmaz.
*/
return [

    'actions' => [
        'view' => 'Görüntüle',
        'create' => 'Ekle',
        'update' => 'Düzenle',
        'delete' => 'Sil',
    ],

    /*
    | Alan bazlı yetki yok: adresin her alanı "görüntüle" ile görünür.
    */
    'fields' => [],

    /*
    | "Görüntüle" dışındaki her yetki (ekle, düzenle, sil) aynı grupta
    | "görüntüle" olmadan işe yaramaz.
    */
    'requires_view' => true,

    /*
    | Yerleşik rollerin şablonu (rol oluşturulurken bir kez yazılır; sonra
    | Roller ekranından değiştirilir).
    */
    'templates' => [
        'network' => [
            'address_group' => [
                'all' => ['view', 'create', 'update', 'delete'],
            ],
        ],
        'teknik' => [
            'address_group' => [
                'all' => ['view'],
            ],
        ],
    ],

    'resources' => [
        'address_group' => [
            'label' => 'Adres grupları',
            'model' => AddressGroup::class,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
    ],

];
