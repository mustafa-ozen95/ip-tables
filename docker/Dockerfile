# =============================================================================
#  ip-tables — IP Adres Tabloları, tek imaj (appliance)
# =============================================================================
#
#  Uygulama tek konteyner: nginx + php-fpm + kuyruk işçisi + zamanlayıcı,
#  hepsini supervisord yönetir. Veritabanı (MariaDB) ve redis AYRI servisler
#  (docker-compose.yml); TLS'i önündeki vekil bitirir, panel yalnız 80'i
#  dinler. Ayrıntı: docker/README.md.
#
#  Derleme:  docker compose build   (ya da: docker build -f docker/Dockerfile .)
# =============================================================================

ARG PHP_VERSION=8.5

# -----------------------------------------------------------------------------
#  1. Arayüz varlıkları
# -----------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /uygulama

# Önce yalnızca kilit dosyaları: kaynak değişince npm ci yeniden koşmasın.
COPY package.json package-lock.json ./
# --ignore-scripts: Playwright postinstall'ı tarayıcı indirmeye kalkıyor.
RUN npm ci --ignore-scripts

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# -----------------------------------------------------------------------------
#  2. PHP tabanı — hem bağımlılık çözümü hem çalışma zamanı bunu paylaşır
# -----------------------------------------------------------------------------
FROM debian:bookworm-slim AS php-base

ARG PHP_VERSION
# Güvenlik sürümü çıkınca artır: değeri değişen katman önbellekten gelmez, Debian ve PHP
# paketleri yeniden çekilir; depodaki PHP bundan eskiyse derleme durur.
ARG PHP_MIN_VERSION=8.5.12
ENV DEBIAN_FRONTEND=noninteractive

# PHP 8.5 Debian'da yok; Sury deposu eklenir.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
 && curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ bookworm main" \
      > /etc/apt/sources.list.d/sury-php.list \
 && apt-get update \
 && apt-get upgrade -y --no-install-recommends \
 && apt-get install -y --no-install-recommends \
      php${PHP_VERSION}-cli \
      php${PHP_VERSION}-fpm \
      php${PHP_VERSION}-bcmath \
      php${PHP_VERSION}-curl \
      php${PHP_VERSION}-gd \
      php${PHP_VERSION}-intl \
      php${PHP_VERSION}-mbstring \
      php${PHP_VERSION}-mysql \
      php${PHP_VERSION}-readline \
      php${PHP_VERSION}-redis \
      php${PHP_VERSION}-xml \
      php${PHP_VERSION}-zip \
 && rm -rf /var/lib/apt/lists/* \
 && php${PHP_VERSION} -r 'exit(version_compare(PHP_VERSION, getenv("PHP_MIN_VERSION"), ">=") ? 0 : 1);' \
    || { echo "PHP en az $PHP_MIN_VERSION olmalı" >&2; exit 1; }
# NOT: php8.5-opcache diye ayrı bir paket YOK; 8.5'te opcache php-common
# içinde geliyor. 8.3 ve öncesinde ayrıydı, alışkanlıkla eklenirse derleme
# "Unable to locate package" ile düşer.

# "php" her yerde 8.5 demek olsun: zamanlayıcı, artisan ve supervisord aynı
# ikiliyi çağırmalı.
RUN update-alternatives --set php /usr/bin/php${PHP_VERSION}

# -----------------------------------------------------------------------------
#  3. PHP bağımlılıkları
# -----------------------------------------------------------------------------
FROM php-base AS vendors

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /uygulama

# Kaynak kod olmadan çözüm yapılamaz (post-autoload-dump artisan çağırıyor),
# bu yüzden önce kilit dosyaları çözülür, betikler sonraya bırakılır.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# -----------------------------------------------------------------------------
#  4. Çalışma zamanı
# -----------------------------------------------------------------------------
FROM php-base AS runtime

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive \
    PHP_VERSION=${PHP_VERSION} \
    TZ=Europe/Istanbul

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      nginx \
      # Yalnız istemci: veritabanını bekleme ve Ayarlar → Veritabanı taşıması
      # (mariadb-dump). Sunucu compose'taki db servisi, redis de öyle.
      mariadb-client \
      supervisor \
      procps \
      tzdata \
      gosu \
      openssl \
      curl \
 && rm -rf /var/lib/apt/lists/* \
 && ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime

# Uygulama kullanıcısı: hiçbir servis root olarak çalışmaz.
RUN useradd --system --create-home --home-dir /uygulama --shell /usr/sbin/nologin ipt

WORKDIR /uygulama

COPY --chown=ipt:ipt . .
COPY --from=vendors --chown=ipt:ipt /uygulama/vendor ./vendor
COPY --from=assets --chown=ipt:ipt /uygulama/public/build ./public/build

# Yapılandırmalar ve başlatıcı
COPY docker/rootfs/ /

# PHP yapılandırmaları sürüm numaralı dizinlerde durur; ARG ile yerleştirilir.
RUN cp /etc/ip-tables/php.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-ip-tables.ini \
 && cp /etc/ip-tables/php.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-ip-tables.ini \
 && rm -f /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf \
 && cp /etc/ip-tables/php-fpm-pool.conf /etc/php/${PHP_VERSION}/fpm/pool.d/ipt.conf \
 && chmod 0755 /usr/local/bin/ip-tables-entrypoint /usr/local/bin/dc-* \
 && rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf \
 && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
 && chown -R ipt:ipt storage bootstrap/cache \
 && chmod -R 0775 storage bootstrap/cache

VOLUME ["/uygulama/storage/app", "/uygulama/storage/logs"]

EXPOSE 80

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
  CMD curl -fsS http://127.0.0.1/up || exit 1

# Hangi commit'ten derlendiği: panel kendi sürümünü buradan bilir (Ayarlar →
# Güncelleme). Son katmanda: değeri her derlemede değişir, önbelleği bozmasın.
ARG APP_VERSION=""
ENV APP_VERSION=${APP_VERSION}
# APP_VERSION verilmezse (NineDeploy gibi platformlar derlemeye commit vermez)
# bağlamdaki .git'ten okunur ve /uygulama/VERSION'a yazılır; config önce
# ortama, sonra bu dosyaya bakar. .git yoksa (zip) dosya boş kalır.
RUN --mount=type=bind,target=/tmp/baglam sh /tmp/baglam/docker/version-read.sh > /uygulama/VERSION

ENTRYPOINT ["/usr/local/bin/ip-tables-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
