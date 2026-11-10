# IPT — IP Adres Tabloları

[English](README.md) · **Türkçe**

Kurumun IP adres planını gruplar hâlinde tutan panel: kim hangi adresi,
hangi amaçla kullanıyor, hangi grupta duruyor.

Kardeş projeler: EYS (erişim kasası), NYS (ağ paneli) ve Kimlik Yönetim
Merkezi (kimlik sağlayıcı). Arayüz ve platform (yetki, API, merkezden giriş,
güncelleme, ayarlar, denetim kaydı) onlarla ortaktır.

## Gruplar

Adresler **gruplarda** durur; yetki de grup bazında verilir. Kurulumda dört
yerleşik grup gelir, istenen kadar grup eklenebilir:

| Grup | Tür | Kabul ettiği |
| --- | --- | --- |
| IPv4 | `ipv4` | Genel IPv4 adres ve ağları |
| IPv6 | `ipv6` | Genel IPv6 adres ve ağları |
| Yerel IPv4 | `local-ipv4` | Özel / yerel IPv4 (10/8, 172.16/12, 192.168/16 …) |
| Yerel IPv6 | `local-ipv6` | Özel / yerel IPv6 (fc00::/7, fe80::/10 …) |
| *(yeni grup)* | `mixed` | Her adres |

Grubun türü hangi adreslerin girebileceğini belirler. Kural iki yoldan da
uygulanır: türe uymayan adres gruba eklenemez, içinde uymayan adres varken
grubun türü değiştirilemez. "Yerel" sayılan aralıklar `IP_LOCAL_RANGES` ile
değiştirilebilir.

Gruplar alt gruplara bölünebilir (ör. "Müşteri B → DMZ").

## Adresler

Tek adres (`192.0.2.10`) ya da ağ (`198.51.100.0/24`, `2001:db8::/48`)
girilir. Adres kanonik biçimde saklanır (`2001:0DB8::0001` → `2001:db8::1`),
aynı grupta ikinci kez girilemez ve **sayısal** sıralanır (`.9`, `.10`'dan
önce gelir).

## Yetki

Rol **canlı** okunur; kullanıcıya kopyalanmaz. Kişinin satırları yalnızca
istisnadır. Karar sırası:

1. Kişinin gruba özel satırı
2. Kişinin genel satırı
3. Rolün gruba özel yetkisi
4. Rolün genel yetkisi
5. Reddet (varsayılan kapalı)

Ekle, düzenle ve sil yetkileri o grupta **görüntüle** olmadan işe yaramaz.
Yerleşik roller merkezdeki adlarla aynıdır: `admin`, `network` (her grupta
tam yetki), `teknik` (her grubu görür, varsayılan), `muhasebe` (yetkisiz).

## API

`/api/v1/addresses` salt okunur; Ayarlar → API'den alınan jetonla
(`Authorization: Bearer …`). Jeton sahibini aşamaz: sahibinin göremediği
adres jeton için yoktur (404). Süzgeçler: `grup` (uuid), `tur`, `q`.
Belge panelde Ayarlar → API altında.

## Denetim kaydı

Her satır bir öncekinin özetini taşır ve özet **HMAC-SHA256** ile üretilir.
Model düzeyinde güncelleme ve silme kapalıdır; silmenin tek kapısı
`activity:prune` komutudur ve o da gerekçe isteyip kendini zincire yazar.

## Kurulum

Docker ile: [`docker/README.md`](docker/README.md).

Geliştirme:

```bash
composer setup          # bağımlılıklar, .env, anahtar, göçler, derleme
php artisan admin:add --name="Ad Soyad" --email=ad@ornek.test
```

Depoda hiçbir gerçek veri yoktur: seeder yalnızca ilk yöneticiyi açar,
testler ve görsel test verisi belgeleme aralıklarını (RFC 5737 / 3849)
kullanır.

## Komutlar

| Komut | İş |
| --- | --- |
| `identity:sync` | Merkezdeki rol ve durum değişikliklerini yerel hesaplara uygular |
| `update:check` | GitHub'daki son imaj derlemesiyle çalışan sürümü karşılaştırır |
| `activity:prune` | Denetim kaydını budar; gerekçe ister |
| `admin:add` | İlk yöneticiyi açar; parola komut satırına yazılmaz |
| `access:prepare-visuals` | Görsel testler için sqlite'ı sıfırdan kurar |

## Testler

```bash
composer test                                   # tam takım
php artisan access:prepare-visuals && npx playwright test
```

## Kurallar

Kod standartları ve güvenlik denetimi `CLAUDE.md`'de, arayüz kuralları
`DESIGN.md`'de. Özetle: her controller yetki taşır, sıralı birincil anahtar
dışarı çıkmaz (uuid), süzme veritabanında yapılır, her düzeltme bir testle
kilitlenir.
