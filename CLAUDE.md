# Laravel Development Standards

## ⛔ TASARIMA DOKUNMADAN ÖNCE: DESIGN.md

Bu panelin arayüzü KARDEŞ PANELLERLE ORTAKTIR. CSS dosyaları, sayfa kabuğu,
üst çubuk, giriş ekranı ve `ui` bileşenleri byte byte aynıdır; biri
değişecekse hepsi birlikte değişir.

**Arayüzde bir şey değiştirmeden önce `DESIGN.md` dosyasını oku.** Yeni
sınıf/renk/ölçü icat etme, var olan bileşenleri kullan, tek panelde
"iyileştirme" yapma.

## Ortak Kurallar

Tüm panellerde geçerli Laravel, frontend, riskli işlem ve kod üretim kuralları:
@../ortak/LARAVEL.md

## Güvenlik (Öncelikli)

Güvenlik bu projede diğer başlıklardan önce gelir. Bir özellik güvenli değilse
tamamlanmış sayılmaz. Aşağıdaki açık sınıfları her yeni ekranda tekrar
kontrol edilir.

### Kimlik ve yetki

- Her rota ya `auth` altında olacak ya da neden olmadığı yorumla açıklanacak.
- Her controller `HasMiddleware` ile `can:` tanımlar veya `authorize()` çağırır.
  Yetkisiz bırakılmış tek bir controller bile kabul edilmez.
- Dış dünyaya etki eden işlemler (e-posta gönderme, uzak oturum açma, ödeme)
  ayrı bir yetki ister; "görüntüle" yetkisi bunları kapsamaz.
- Kullanıcı rolünü yalnızca yönetici değiştirebilir. "Kullanıcıları düzenle"
  yetkisi, kendini yönetici yapma yetkisi değildir.

### Nesne erişimi (IDOR)

- İç içe rotalarda alt kayıt üst kayıt üzerinden çözülür: `scopeBindings()`.
  Bunsuz A kaydında yetkisi olan biri B kaydının alt satırını değiştirebilir.
- Liste sorguları kullanıcıya göre daraltılır (`visibleTo($user)` gibi bir
  scope ile); "hepsini getir sonra ekranda gizle" güvenlik değildir.
- Sıralı birincil anahtar dışarı ÇIKMAZ; URL, sorgu parametresi, form değeri ve
  DOM kimliği uuid taşır.

### Aynı satıra giden iki yol

Bir veriyi değiştirmenin iki yolu varsa İKİSİ DE korunmalı. Aynı alanı yazan
iki ekrandan yalnızca birinde doğrulama varsa ikinci yol korumayı sessizce
atlar.
Yeni bir ekran eklerken sorulacak soru: "bu alanı yazan başka bir yol var mı,
o da aynı kuralı uyguluyor mu?" Kural kopyalanmaz, PAYLAŞTIRILIR.

### Veri

- Parola, jeton ve anahtar alanları `encrypted` cast ile saklanır ve `$hidden`
  içindedir; hiçbir ekranda ve JSON yanıtında basılmaz.
- Toplu atama `$fillable` ile sınırlıdır; `$request->all()` kullanılmaz.
- Oturum çerezi `Secure` işaretlidir.

### Denetim

Yeni bir ekran veya uç eklendiğinde şunlar çalıştırılır ve çıktısı boş olmalıdır:

```
# Yetkisiz controller. İstisna bilinçliyse sınıf docblock'una
# "Yetkilendirme: <neden>" satırı yazılır; denetim onu kabul eder.
for f in app/Http/Controllers/*.php app/Http/Controllers/*/*.php; do
  grep -q "middleware()\|authorize(\|Yetkilendirme:" $f || echo "$f"
done

# auth dışı rota. API uçları "auth:api" taşır; ikisi de kabul edilir.
php artisan route:list --except-vendor --json | python3 -c "
import json,sys
for r in json.load(sys.stdin):
    if not any(m.startswith('auth') for m in (r.get('middleware') or [])):
        print(r['uri'], r.get('name'))
"
```

Her düzeltme bir regresyon testiyle kilitlenir: açığın kapandığını değil,
bir daha açılamayacağını kanıtlarız.
