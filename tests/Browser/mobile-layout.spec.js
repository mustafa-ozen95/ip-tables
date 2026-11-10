import { expect, test } from '@playwright/test';

/**
 * Dar ekran düzeni — taşma ve kırılma nöbetçisi.
 *
 * Telefonda bozulan üç şey ölçülür:
 *
 *  1. Hiçbir sayfa YATAY KAYMAZ. Yatay kaydırma dar ekranda içeriğin bir
 *     kısmını görünmez yapar ve kullanıcı orada bir şey olduğunu bilmez.
 *  2. Şeritteki sayaç TEK SATIRDA kalır ("Tümü 3/10 hazır" üç satıra
 *     bölünüyordu).
 *  3. Tablo satırlarında ETİKETSİZ hücre boş şerit açmaz: etiketi olmayan
 *     hücre 6,5rem'lik sütunu ayırıyor, değerler sahipsiz sağa itiliyordu.
 */
const EPOSTA = process.env.E2E_EMAIL;
const PAROLA = process.env.E2E_PASSWORD;

async function girisYap(page) {
    await page.goto('/login');

    const katlanir = page.locator('details.gate__collapsible');

    if (await katlanir.count() && await katlanir.getAttribute('open') === null) {
        await page.locator('summary.gate__collapsible-title').click();
    }

    await page.fill('#email', EPOSTA);
    await page.fill('#password', PAROLA);
    await page.click('form button[type="submit"]');
    await expect(page).toHaveURL(/addresses/);
}

const SAYFALAR = [
    ['IP adresleri', '/addresses'],
    ['gruplar', '/panel/groups'],
    ['kullanıcılar', '/panel/users'],
    ['kayıtlar', '/panel/logs'],
];

for (const [ad, adres] of SAYFALAR) {
    test(`${ad} ekranı yatay kaymaz`, async ({ page }) => {
        await girisYap(page);
        await page.goto(adres);

        /*
         * Sütun şeridi BİLEREK yatay kayar (bölmeler arası geçiş); ölçülen
         * şey sayfanın kendisi.
         */
        const tasma = await page.evaluate(() => ({
            govde: document.body.scrollWidth,
            pencere: window.innerWidth,
        }));

        expect(tasma.govde, `${adres} yatay kayıyor`).toBeLessThanOrEqual(tasma.pencere + 1);
    });
}

/**
 * Yan yana duran alan ve düğme AYNI BOYDA olmalı.
 *
 * Dokunmatikte form alanları 32 piksele çıkıyor (16 piksel yazı, iOS'un
 * kendiliğinden yakınlaştırmaması için); düğmeler 24 pikselde kalınca
 * arama kutusu ile "Filtrele" farklı boyda duruyordu.
 */
test('süzgeç düğmesi yanındaki alanla aynı boyda', async ({ page }) => {
    await girisYap(page);
    await page.goto('/addresses');

    const alan = await page.locator('.column__head .filter__input').first().boundingBox();
    const dugme = await page.getByRole('button', { name: 'Filtrele' }).boundingBox();

    expect(alan?.height ?? 0).toBeGreaterThan(0);

    /* Dar ekranda arama kutusu kendi satırına iner (form.css · filter-bar); yan yana değillerse ölçülecek bir şey yok. */
    const yanYana = Math.abs((alan.y + alan.height / 2) - (dugme.y + dugme.height / 2)) < 4;
    test.skip(! yanYana, 'Arama kutusu ayrı satırda.');

    expect(Math.abs(alan.height - dugme.height)).toBeLessThanOrEqual(2);
});

/**
 * SATIR EYLEMLERİ SAĞDA.
 *
 * Değerler sağda hizalanırken düğmelerin sola kaçması satırı iki ayrı hizaya
 * bölüyordu. Kural bir kez kopyalanmıştı ve kopya "sola yasla" derken aslı
 * "sağa yasla" diyordu; sonra gelen kazanıyordu.
 */
test('satır eylemleri sağa yaslanır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/panel/groups');

    /* Alt grubu olmayan grubun tablosunda yalnız "boş" satırı var. */
    const satir = page.locator('.table tbody tr', { has: page.locator('.table__row-actions') }).first();
    const eylemler = satir.locator('.table__row-actions');

    await expect(eylemler).toBeVisible();

    const satirKutu = await satir.boundingBox();
    const eylemKutu = await eylemler.boundingBox();

    const sagBosluk = (satirKutu.x + satirKutu.width) - (eylemKutu.x + eylemKutu.width);
    const solBosluk = eylemKutu.x - satirKutu.x;

    expect(sagBosluk, 'eylemler sağa yaslanmalı').toBeLessThan(solBosluk);
});

test('tablo hücreleri etiketsiz boş şerit açmaz', async ({ page }) => {
    await girisYap(page);
    await page.goto('/panel/logs');

    /* Etiketi olan her hücre etiketini GÖSTERİR; olmayan şerit açmaz. */
    const bosSerit = await page.evaluate(() => {
        const hucreler = Array.from(document.querySelectorAll('.table td:not([data-label])'));

        return hucreler.some((td) => {
            const once = getComputedStyle(td, '::before');

            return once.display !== 'none';
        });
    });

    expect(bosSerit, 'etiketsiz hücre şerit açıyor').toBe(false);
});
