import { expect, test } from '@playwright/test';

/**
 * Sayfa geçişleri tarayıcıyı YENİLEMEZ.
 *
 * Yenilenip yenilenmediği doğrudan ölçülür: pencereye bir işaret bırakılır
 * ve geçişten sonra hâlâ orada mı diye bakılır. Tam yenilemede o işaret
 * kaybolur — "sayfa değişti mi" sorusunu bundan daha kesin soran bir yol yok.
 */
const EPOSTA = process.env.E2E_EMAIL;
const PAROLA = process.env.E2E_PASSWORD;

/**
 * Merkez açıkken parola formu KATLI gelir; açmadan doldurulamaz.
 *
 * Başlığa TIKLANIR, betikle açılmaz: <details> betiksiz de çalışan bir öğe ve
 * senaryolardan biri tam olarak betiksiz kurulumu sınıyor. `open` özniteliği
 * sunucudan geliyorsa (merkez kapalı kurulum) hiç dokunulmaz.
 */
async function parolaFormunuAc(page) {
    const katlanir = page.locator('details.gate__collapsible');

    if (await katlanir.count() && await katlanir.getAttribute('open') === null) {
        await page.locator('summary.gate__collapsible-title').click();
    }
}

async function girisYap(page) {
    await page.goto('/login');

    await parolaFormunuAc(page);

    await page.fill('#email', EPOSTA);
    await page.fill('#password', PAROLA);
    await page.click('form button[type="submit"]');
    await expect(page).toHaveURL(/addresses/);
}

/** Yenilemede silinecek bir iz bırakır. */
async function isaretBirak(page) {
    await page.evaluate(() => {
        window.__iptIsaret = 'duruyor';
    });
}

const isaret = (page) => page.evaluate(() => window.__iptIsaret ?? null);

test('grup gecisi sayfayi yenilemez', async ({ page }) => {
    await girisYap(page);
    await isaretBirak(page);

    await page.getByRole('link', { name: /^IPv6\b/ }).click();
    await expect(page).toHaveURL(/grup=/);
    await expect(page.locator('.column--fill .eyebrow').first()).toHaveText('IPv6');

    expect(await isaret(page)).toBe('duruyor');
});

test('ust menu gecisi de yenilemez ve baslik degisir', async ({ page }) => {
    await girisYap(page);
    await isaretBirak(page);

    await page.getByRole('link', { name: 'Kayıtlar', exact: true }).click();
    await expect(page).toHaveURL(/logs/);

    expect(await isaret(page)).toBe('duruyor');

    // Başlık sunucudan gelen yeni sayfanınki olmalı.
    expect(await page.title()).not.toBe('');
});

test('geri tusu onceki ekrani getirir', async ({ page }) => {
    await girisYap(page);

    const ilkAdres = page.url();

    await page.getByRole('link', { name: /^IPv6\b/ }).click();
    await expect(page).toHaveURL(/grup=/);

    await isaretBirak(page);
    await page.goBack();

    await expect(page).toHaveURL(ilkAdres);
    await expect(page.locator('.grid-row').first()).toBeVisible();
    expect(await isaret(page)).toBe('duruyor');
});

/** Süzgeç de bir geçiştir: GET formu da yenileme yapmamalı. */
test('arama formu sayfayi yenilemez', async ({ page }) => {
    await girisYap(page);
    await isaretBirak(page);

    await page.fill('.filter__input', 'web');
    await page.press('.filter__input', 'Enter');

    await expect(page).toHaveURL(/q=web/);
    await expect(page.locator('.grid-row .address-row__address')).toHaveCount(3);
    expect(await isaret(page)).toBe('duruyor');
});

/**
 * Geçişten SONRA da bileşenler çalışmalı.
 *
 * Düğüme doğrudan bağlanan bir dinleyici ilk geçişte kopardı; bu test tam
 * olarak onu yakalar.
 */
test('gecisten sonra tema dugmesi calisir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^IPv6\b/ }).click();
    await expect(page).toHaveURL(/grup=/);

    const once = await page.evaluate(() => document.documentElement.dataset.theme);
    await page.locator('[data-theme-toggle]').click();
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).not.toBe(once);
});

test('gecisten sonra adres penceresi acilir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^IPv4\b/ }).click();
    await expect(page).toHaveURL(/grup=/);

    await page.getByRole('link', { name: 'Yeni adres' }).click();
    await expect(page.locator('#record-add')).toBeVisible();
});

test('gecisten sonra silme onayi acilir ve vazgecilir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^IPv4\b/ }).click();
    /* Düz bir adres: bölünmüş bloğun Sil'i yok, yerinde "Bölmeyi kaldır" durur. */
    await page.getByRole('link', { name: '192.0.2.10 işlemleri' }).click();
    await expect(page.locator('#record-actions')).toBeVisible();

    await page.locator('#record-actions').getByRole('button', { name: 'Sil' }).click();
    await expect(page.locator('[data-confirm-dialog]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-confirm-dialog]')).toBeHidden();
});

/**
 * Dar ekranda şerit DOĞRU BÖLMEYE iner.
 *
 * Sütunlar telefonda yan yana sığmıyor; şerit yatay kayıyor ve her bölme
 * neredeyse tam ekran. Şerit en solda kalınca kullanıcı bir kayda dokunuyor
 * ama hâlâ grup listesine bakıyordu — seçtiği şeyin bilgisi ekranın
 * dışındaydı (kayıt seçiliyken scrollLeft 0, detay yüzlerce piksel sağda).
 *
 * Kural tek: en sağdaki görünür bölme, inilen en derin seviyedir. Geniş
 * ekranda şerit taşmaz, kural hiçbir şey yapmaz — bu da sınanıyor. Adres
 * ekranında ayrıntı sütunu yok: kayıt seçmek (⋮) bir pencere açar, şerit
 * adres listesinde kalır.
 */
async function seritDurumu(page) {
    return page.evaluate(() => {
        const serit = document.querySelector('.columns');
        const bolmeler = [...serit.querySelectorAll(':scope > .column')]
            .filter((bolme) => getComputedStyle(bolme).display !== 'none');
        const son = bolmeler[bolmeler.length - 1];

        return {
            tasiyor: serit.scrollWidth > serit.clientWidth,
            scrollLeft: Math.round(serit.scrollLeft),
            hedef: Math.round(son.offsetLeft - serit.offsetLeft),
            sonBolme: son.className.split(' ')[0],
        };
    });
}

test('serit en derin bolmeye iner (dar ekran), genis ekranda dokunmaz', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^IPv4\b/ }).click();
    await expect(page).toHaveURL(/grup=/);
    await page.waitForTimeout(200);

    const liste = await seritDurumu(page);

    if (liste.tasiyor) {
        expect(liste.scrollLeft).toBeCloseTo(liste.hedef, -1);
    } else {
        expect(liste.scrollLeft).toBe(0);
    }

    // Kayıt seçilince pencere açılır; şerit yine adres listesinde durur.
    await page.getByRole('link', { name: '192.0.2.11 işlemleri' }).click();
    await expect(page.locator('#record-actions')).toBeVisible();
    await page.waitForTimeout(400);

    const kayit = await seritDurumu(page);

    expect(kayit.sonBolme).toBe('column');

    if (kayit.tasiyor) {
        expect(kayit.scrollLeft).toBeCloseTo(kayit.hedef, -1);
        expect(kayit.scrollLeft).toBeGreaterThan(0);
    } else {
        expect(kayit.scrollLeft).toBe(0);
    }
});
