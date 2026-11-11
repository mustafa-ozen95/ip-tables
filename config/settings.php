<?php

declare(strict_types=1);

/**
 * Ayar kataloğu.
 *
 * Anahtar = üzerine bindirilecek config yolu. Ekranın alan türü, doğrulama
 * kuralı ve tip çevrimi hep BURADAN okunur; üç yerde ayrı ayrı tanımlanıp
 * birbirinden ayrışmaz.
 *
 * BURAYA YALNIZCA GERÇEKTEN ETKİSİ OLAN AYARLAR KONUR. Ekranda duran ama
 * hiçbir şeyi değiştirmeyen bir kutu, olmayan bir denetimi varmış gibi
 * gösterir — ve bu, hiç kutu olmamasından kötüdür.
 */
/*
 * Saat dilimi listesi PHP'nin kendi IANA veritabanından gelir; elle yazılmış
 * bir liste güncel kalmazdı. config:cache bunu diziye çevirip dosyaya yazar.
 */
$saatDilimleri = array_combine(
    DateTimeZone::listIdentifiers(),
    DateTimeZone::listIdentifiers(),
);

return [

    'groups' => [

        'panel' => [
            'label' => 'Saat dilimi',
            'description' => 'Kayıtların, zamanlanmış işlerin ve ekranlardaki bütün tarih/saatlerin '
                .'hangi dilime göre gösterileceği. Denetim kaydı takibi için doğru olması şart: '
                .'yanlış dilim, olayların gerçekte ne zaman olduğunu saatlerce kaydırır.',
            'settings' => [

                'app.timezone' => [
                    'label' => 'Saat dilimi',
                    'hint' => 'Türkiye için Europe/Istanbul. Anlar UTC saklanır; bu ayar yalnızca gösterimi değiştirir.',
                    'type' => 'select',
                    'options' => $saatDilimleri,
                    'rules' => ['required', 'timezone'],
                ],

            ],
        ],

        'security' => [
            'label' => 'Güvenlik',
            'description' => 'Oturum ve giriş davranışı.',
            'settings' => [

                'session.lifetime' => [
                    'label' => 'Oturum süresi',
                    'hint' => 'Hareketsiz kalan oturum bu süre sonunda düşer.',
                    'type' => 'int',
                    'unit' => 'dakika',
                    'rules' => ['integer', 'min:5', 'max:1440'],
                ],

                'auth.max_attempts' => [
                    'label' => 'Başarısız giriş sınırı',
                    'hint' => 'Bu sayıdan sonra aynı e-posta ve IP için giriş bir süre kilitlenir.',
                    'type' => 'int',
                    'unit' => 'deneme',
                    'rules' => ['integer', 'min:3', 'max:20'],
                ],
            ],
        ],

        /*
         * Gateway ping turu (bkz. GatewayPing). Kuyruk işçisi ayarları her
         * işten önce yeniden okur; değişiklik bir sonraki turda geçerlidir.
         */
        'ping' => [
            'label' => 'Gateway ping',
            'description' => 'Blokların gateway\'lerine düzenli ping. Tur gateway\'leri paketlere böler; '
                .'paketteki gateway\'ler aynı anda, paketler sırayla pinglenir.',
            'settings' => [

                'ip.gateway_ping.interval_minutes' => [
                    'label' => 'Tur aralığı',
                    'hint' => 'Her gateway bu sürede bir kez kontrol edilir.',
                    'type' => 'int',
                    'unit' => 'dakika',
                    'rules' => ['integer', 'min:1', 'max:1440'],
                ],

                'ip.gateway_ping.count' => [
                    'label' => 'Ping adedi',
                    'hint' => 'Kontrolde gateway başına saniyede bir gönderilir; biri yanıt alırsa gateway yanıt veriyor sayılır.',
                    'type' => 'int',
                    'unit' => 'ping',
                    'rules' => ['integer', 'min:1', 'max:10'],
                ],

                'ip.gateway_ping.concurrency' => [
                    'label' => 'Aynı anda pinglenen gateway',
                    'hint' => 'Tur gateway\'leri bu boyda paketlere böler; bir paket ping adedi kadar saniye sürer.',
                    'type' => 'int',
                    'unit' => 'gateway',
                    'rules' => ['integer', 'min:1', 'max:50'],
                ],
            ],
        ],

        /*
         * Merkez ile giriş.
         *
         * Açıldığında giriş ekranı merkezi ÖNE alır ve parola formunu
         * katlar — kapatmaz. Ayar tek başına yetmez: istemci bilgileri yoksa
         * akış zaten çalışmaz (bkz. CenterClient::acikMi). İkisi ayrı
         * durur ki bir ayar, olmayan bir yeteneği varmış gibi gösteremesin.
         */
        'kimlik' => [
            'label' => 'Kimlik Yönetim Merkezi',
            'description' => 'Kullanıcılar parolalarını buraya değil, Kimlik Yönetim Merkezi’ne yazar. '
                .'Panelin kendi parola girişi kapanmaz; merkez erişilemez olduğunda panele girmenin tek yolu odur.',
            'settings' => [

                'identity.acik' => [
                    'label' => 'Kimlik Yönetim Merkezi ile giriş',
                    'hint' => 'Açıkken giriş ekranı merkezi öne alır; parolayla giriş katlanmış hâlde durur '
                        .'ve merkez erişilemediğinde kullanılabilir.',
                    'type' => 'bool',
                    'rules' => ['boolean'],
                ],

                'identity.yonetici_devri' => [
                    'label' => 'Merkezdeki yönetici burada da yönetici olsun',
                    'hint' => 'KAPALI TUTULMASI ÖNERİLİR. Merkezin yöneticisi kimlikleri yönetir; buranın '
                        .'yöneticisi bütün sistemlerin parolalarını görür. Açıkken merkezde rol dağıtabilen '
                        .'biri, buradaki hiçbir yöneticinin haberi olmadan kendini burada da yönetici '
                        .'yapabilir. Kapalıyken yeni hesap varsayılan rolle doğar.',
                    'type' => 'bool',
                    'rules' => ['boolean'],
                ],
            ],
        ],
    ],
];
