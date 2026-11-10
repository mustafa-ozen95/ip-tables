# ip-tables — konteyner kurulumu

Kurulum deponun kökündeki `docker-compose.yml` ile yapılır. Dosya Compose
standardındadır (compose-spec.io): aynı dosya `docker compose` ile de, NineDeploy,
Coolify, Dokploy, Portainer gibi bir panelin "Git deposundan compose" kurulumuyla da
çalışır. Ayarların hepsi ortamdan gelir; liste ve açıklamalar `.env.example`'da.

    cp .env.example .env          # en az APP_URL, DB_PASSWORD, ADMIN_EMAIL; chmod 600 .env
    docker compose up -d --build

Bir platformda: depoyu "compose" türünde ekleyin (dosya `docker-compose.yml`), ana
servis `panel`, port 80, sağlık yolu `/up`; `.env.example`'daki adları platformun
değişken ekranına girin.


## Vekil: alan adı ve TLS

**HTTP portu dışarı açılmaz.** Alan adını ve TLS'i önündeki vekil verir (platformun
kendisi ya da Caddy/nginx); panel isteğin HTTPS olduğunu `X-Forwarded-Proto`'dan bilir.
Kendi Caddy'nizle kuracaksanız örnek kural `docker/proxy.caddy`'de. Vekilsiz deneme:
`cp docker-compose.override.example.yml docker-compose.override.yml` (düz HTTP, port 8080).

NineDeploy örneği: compose türünde depo, ana servis `panel`, port 80,
sağlık yolu `/up`, alan adı panelin adı; `APP_URL` o adın `https://` ile başlayan hâli.


## Servisler ve hacimler

| Servis | Ne |
|---|---|
| `panel` | nginx, php-fpm, kuyruk işçisi, zamanlayıcı |
| `db` | MariaDB 11.4; `DB_REPLICAS=0` ile kalkmaz (dışarıdaki veritabanı) |
| `redis` | önbellek, oturum, kuyruk; kalıcılık kapalı (yeniden başlatmada oturumlar düşer, veri düşmez) |

Adlar `STACK_PREFIX` ile başlar (varsayılan `ip-tables`): `ip-tables_storage` (`storage/app`:
APP_KEY verilmediyse, ilk parola, Ayarlar → Veritabanı bağlantısı), `ip-tables_database`,
`ip-tables_logs`. Aynı sunucuda ikinci kurulum `STACK_PREFIX`'i değiştirir.

`NETWORK_ISOLATED=true` konteynerleri KYM'den ve dışarıdan yalıtır (deneme ya da taşıma
kurulumu).


## Neden panel tek konteyner

nginx, php-fpm, kuyruk işçisi ve zamanlayıcı aynı kodu ve aynı `storage`'ı
paylaşır; onları `supervisord` tek konteynerde yönetir. Veritabanı ve redis
compose'ta ayrı servis. Yatay ölçekleme gerekmez.

## Durdurma — varsayılan süre YETMEZ

    docker compose down                          # süre compose'da tanımlı
    docker stop -t 900 ip-tables              # düz docker ile

Kapanış **ne kadar sürüyorsa o kadar** beklenmelidir; verilen sayı bir hedef
değil, asla devreye girmemesi gereken bir tavandır. Konteyner işi bitince
hemen çıkar.

`docker stop` varsayılan olarak **10 saniye** bekler ve süre dolunca SIGKILL
gönderir (çıkış kodu 137). Kuyrukta süren bir iş (ör. denetim zinciri
doğrulaması) yarıda kesilmesin diye compose'ta süre 15 dakikadır.

## İlk açılışta ne olur

Eksik olan her şey üretilir ve **hacimde saklanır**:

| Üretilen | Nerede durur | Not |
| --- | --- | --- |
| `APP_KEY` | `storage/app/app-key.txt` | Yedekleyin |
| Yönetici parolası | `storage/app/initial-password.txt` | Giriş yapıp değiştirin, sonra silin |

İlk yönetici `ADMIN_EMAIL` ile ve **yalnızca kullanıcı tablosu boşken** açılır; adres
verilmezse hesap hiç açılmaz. Parola sabit **değildir**: verilmezse rastgele üretilir
(imaj herkeste aynı olduğu için sabit parola herkeste aynı parola demek olurdu).
Örnek veri hiç yazılmaz; yerleşik dört grup (IPv4, IPv6, Local IPv4, Local IPv6)
göçle gelir ve boştur.

## ⚠ APP_KEY'i yedekleyin

Şifreli alanlar (merkez sırları, Ayarlar → Veritabanı bağlantısı) ve oturumlar
bu anahtara bağlıdır. Anahtar kaybolursa bunlar okunamaz.

Anahtar hacimde saklandığı için yeniden başlatmada korunur. `APP_KEY` ile
veritabanı **birlikte** yedeklenmelidir; biri olmadan öbürü işe yaramaz.

## Zamanlanmış işler

Konteyner dışı kurulumda zamanlayıcı tek bir cron satırına bağlıdır ve o satır
**unutulabilir**; unutulursa kimlik eşitleme hiç koşmaz.
Konteynerde `schedule:work` supervisord altında durur; unutulacak bir yer yok.

| İş | Sıklık | Ne yapar |
| --- | --- | --- |
| `identity:sync` | 5 dakika | Merkezde erişimi kalkan burada da kaybetsin |
| `update:check` | her sabah 06:50 | Yeni sürüm var mı (bkz. aşağısı) |

## Güncelleme kontrolü

Ayarlar → Güncelleme (yalnız yönetici) çalışan sürümü GitHub'da main dalının **son başarılı
imaj derlemesiyle** karşılaştırır: kaç commit geride, hangi commit'ler yeni. Yön de ölçülür;
çalışan sürüm son derlemeden yeniyse "güncelleme" denmez. Kontrol her sabah 06:50'de kendiliğinden
yapılır (`update:check`). Yeni sürüm varsa yöneticiler panelin üstünde uyarı görür.
Ekran **yalnızca bakar**; güncelleme aşağıdaki yoldan, yedekle yapılır.

- Çalışan sürüm imaja derlemede yazılır (`ARG APP_VERSION` → `APP_VERSION`; iş akışı commit'i verir).
- Depo açık: jeton gerekmez, GitHub'a jetonsuz sorulur (sınır IP başına saatte 60 istek). Depo
  özelse `UPDATE_TOKEN` salt okunur bir jeton olmalı (fine-grained, yalnız panel depoları,
  Contents ve Actions: read). Erişilemezse kontrol "hata" der, panelin başka yeri etkilenmez.
- Çalışan sürüm derlemede imaja yazılır (`ARG APP_VERSION`, iş akışı commit'i verir). Derlemeye commit
  vermeyen platformlarda (NineDeploy gibi) bağlamdaki `.git`'ten okunur (`docker/version-read.sh`;
  `.dockerignore` yalnız `HEAD` ve ref'leri alır).
- Panel GitHub'a çıkamıyorsa (kapalı ağ) kontrol "erişilemedi" der.

## Kimlik Yönetim Merkezi ile giriş

`IDENTITY_ENABLED=true` ise panel kendi giriş formu yerine merkeze yönlendirir.
İstemci kimliği ve sırrı merkezdeki "İstemciler" ekranından alınır; dönüş
adresi oradaki kayıtla **birebir** aynı olmalıdır.

Merkez şirket içi bir adresteyse ve sertifikası genel bir otoriteden değilse
`IDENTITY_CA_CERT` ile merkezin kök sertifikası gösterilir. Doğrulamayı kapatma
seçeneği **yoktur**: jeton takası sırasında araya giren biri istemci sırrını
okurdu.

## Veritabanı

Panel veritabanını ortamdan alır (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`). Varsayılan compose'taki `db` servisi; parolası `DB_PASSWORD`, ilk
açılışta kullanıcı ve veritabanı bununla kurulur. Açılışta panel veritabanını en çok
üç dakika bekler, gelmezse açılmaz (konteyner yeniden dener).

Dışarıdaki ya da platformun yönettiği bir MariaDB/MySQL: `DB_*`'ı ona çevirin ve
`DB_REPLICAS=0` deyin. Göçler yine açılışta koşar: verilen kullanıcının şema
değiştirme yetkisi olmalı.

Oturumun saat dilimi konteynerin saatidir (`DB_TIMEZONE`, varsayılan `+03:00`): UTC'de
çalışan bir sunucuda TIMESTAMP sütunları 3 saat kaymış okunmasın.

### Panelden taşıma (Ayarlar → Veritabanı)

Yalnız yönetici. Panel hedefi sınar: bağlantı, sürüm, hedefin boş ve şu anki
veritabanından farklı olması, göçlerin ihtiyaç duyduğu yetkiler (tablo, dizin, yabancı
anahtar, değişiklik, yazma, silme). Sınama geçerse **Taşı** konteyneri yeniden başlatır.
Açılışta panel kapalıyken şu anki veritabanı hedefe kopyalanır ve her tablonun satır
sayısı iki tarafta karşılaştırılır. Bir adım tutmazsa panel şu anki veritabanıyla açılır
ve sebep sekmede görünür.

Panelden tanımlanan bağlantı `storage/app/database/connection.json` dosyasında APP_KEY ile
şifreli durur ve ortamdaki `DB_*` değerlerinin yerine geçer. `state.json` taşımanın
aşamasını tutar ve sır içermez. Yeniden başlatmayı konteyner içindeki `dc-restart`
yapar; bunun için yeniden başlatma politikası **`unless-stopped`** olmalı (compose'ta öyle).

Taşıma bittikten sonra eski veritabanına **kendiliğinden dönülmez**; taşıma anındaki
hâliyle durur. Bilerek dönmek için: uzak veritabanının dökümünü eskisine yükleyin,
sonra `storage/app/database` altındaki `connection.json` ve `state.json`'u silip
konteyneri yeniden başlatın. Dökümü yüklemezseniz taşımadan sonraki her şey kaybolur.

APP_KEY değişirse bağlantı dosyası çözülemez ve panel açılmaz: APP_KEY'i yedekleyin.


## Güncelleme

    git pull && docker compose up -d --build

Bir platformda: yeniden dağıtın (platform depoyu çekip derler). Göçler her açılışta
`--isolated` ile koşar; iki örnek aynı anda kalksa bile bir kez çalışır.

GitHub Actions her main'de imajı derleyip `ghcr.io/mustafa-ozen95/ip-tables` altına da
yükler: hazır imaj isteyen kurulum derlemek yerine onu kullanabilir.
