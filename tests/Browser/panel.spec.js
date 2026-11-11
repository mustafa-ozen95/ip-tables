import { expect, test } from '@playwright/test';

/**
 * Görsel ve akış testleri.
 *
 * Sorulan soru "sayfa açıldı mı" değil: adresler doğru sırada mı, görülmemesi
 * gereken bir şey sızıyor mu, dar ekranda yerleşim bozuluyor mu.
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

test('giris ekrani cizilir ve tema token\'lari uygulanir', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('.gate__mark')).toHaveText('IPT');

    // Stil yüklenmediyse zemin saydam kalır; token'ın gerçekten uygulandığını görelim.
    const zemin = await page.evaluate(() =>
        getComputedStyle(document.body).backgroundColor);
    expect(zemin).not.toBe('rgba(0, 0, 0, 0)');

    await expect(page).toHaveScreenshot('giris.png', { maxDiffPixelRatio: 0.02 });
});

test('oturumsuz adres listesi girise yonlenir', async ({ page }) => {
    await page.goto('/addresses');
    await expect(page).toHaveURL(/login/);
});

test('liste uc sutun olarak cizilir', async ({ page }, testInfo) => {
    await girisYap(page);

    await expect(page.locator('.column').first()).toBeVisible();

    /*
     * Sütun başlığı telefonda BİLİNÇLİ olarak gizlenir (panel-custom.css,
     * 720px altı): dar ekranda satırlar yığılıyor ve başlık hiçbir sütuna
     * denk gelmiyor. Beklenti cihaza göre değişir.
     */
    const dar = (page.viewportSize()?.width ?? 0) <= 720;
    const baslik = page.locator('.grid-row__head.address-row');

    if (dar) {
        await expect(baslik).toBeHidden();
    } else {
        await expect(baslik).toBeVisible();
    }

    // Gruplardan biri seçilip adresin ⋮ penceresi açılır (ayrıntı sütunu yok).
    await page.locator('.row-item', { hasText: 'IPv4' }).first().click();
    await page.locator('.grid-row a[title="İşlemler"]').first().click();

    await expect(page).toHaveURL(/kayit=/);
    await expect(page.locator('#record-actions')).toBeVisible();
});

/** Adres sırası metin değil sayıdır; tekil adres öneksiz gösterilir. */
test('adresler sayisal siralanir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^IPv4\b/ }).click();
    await expect(page.locator('.column--fill .eyebrow').first()).toHaveText('IPv4');

    const adresler = await page.locator('.grid-row .address-row__address').allTextContents();

    expect(adresler.map((a) => a.trim())).toEqual(['192.0.2.10', '192.0.2.11', '198.51.100.0/24']);
});

test('tema degistirilebilir', async ({ page }) => {
    await girisYap(page);

    /* Başlangıç tarayıcının tercihine bağlı; ölçülen şey düğmenin çevirmesi. */
    const once = await page.locator('html').getAttribute('data-theme');
    const ters = once === 'dark' ? 'light' : 'dark';

    await page.click('[data-theme-toggle]');
    await expect(page.locator('html')).toHaveAttribute('data-theme', ters);

    await page.click('[data-theme-toggle]');
    await expect(page.locator('html')).toHaveAttribute('data-theme', once);
});

test('sayfa yatay kaymaz', async ({ page }) => {
    await girisYap(page);

    const tasma = await page.evaluate(() =>
        document.documentElement.scrollWidth - document.documentElement.clientWidth);

    expect(tasma).toBeLessThanOrEqual(1);
});

test('listenin son satiri ekranin dibine yapismaz', async ({ page }) => {
    await girisYap(page);

    /*
     * Son satır pencerenin en dibindeyken tarayıcı, bağlantı adresini tam
     * oraya basıyor ve satırın üstünü kapatıyordu. Kaydırmanın sonunda bir
     * boşluk kalmalı.
     */
    const govde = page.locator('.column--fill .column__body');
    await govde.evaluate((el) => el.scrollTo(0, el.scrollHeight));

    const sonSatir = await page.locator('.grid-row').last().boundingBox();
    const alan = await govde.boundingBox();

    expect(alan.y + alan.height - (sonSatir.y + sonSatir.height)).toBeGreaterThan(24);
});

test('alttaki kayit secilince liste en uste kaymaz', async ({ page }) => {
    await girisYap(page);

    /*
     * Kayıt seçmek gövdeyi yeniden çiziyor; liste en başa dönüyor, tıklanan
     * satır ekrandan çıkıyordu (bkz. navigation.js · listScrolls).
     */
    const govde = page.locator('.column--fill .column__body');
    await govde.evaluate((el) => el.scrollTo(0, el.scrollHeight));
    const once = await govde.evaluate((el) => el.scrollTop);
    test.skip(once === 0, 'Liste kaydırılacak kadar uzun değil.');

    await page.locator('.column--fill .grid-row a[title="İşlemler"]').last().click();
    await expect(page).toHaveURL(/kayit=/);
    await expect(page.locator('#record-actions')).toBeVisible();

    expect(await govde.evaluate((el) => el.scrollTop)).toBe(once);
});
