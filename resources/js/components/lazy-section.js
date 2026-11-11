/**
 * Kapalı alt grup bölümünün içi açılınca yüklenir.
 *
 * IPv4 listesi bütün parçalarıyla 1,5 MB tutuyor, her geçişte (arama,
 * yerinde düzenleme, ⋮) baştan çizilip ayrıştırılıyordu; bölümler ise
 * kapalı geliyor. Sunucu kapalı bölümün yalnız başlığını çizer, içinde
 * "Bölümü göster" bağlantısı durur (bkz. ip-addresses/partials/section).
 * Bölüm açılınca o bağlantının sayfası alınır; aynı bölümün içi oradan
 * buraya taşınır.
 *
 * Açık bölümler yerinde yeniden çizimde dolu gelir (X-Open-Sections, bkz.
 * navigation.js); burası yalnız yeni açılanı yükler. Yükleme olmazsa
 * bağlantı yerinde kalır, tıklanınca bölümü açık çizen sayfaya gidilir.
 */

const HOLDER = ':scope > [data-lazy-section]';

async function load(section) {
    const holder = section.querySelector(HOLDER);
    const link = holder?.querySelector('a[href]');

    if (! link || holder.dataset.loading !== undefined) {
        return;
    }

    const text = link.textContent;
    holder.dataset.loading = '';
    holder.setAttribute('aria-busy', 'true');
    link.textContent = 'Yükleniyor…';

    try {
        const response = await fetch(link.href, {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        });
        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
        const loaded = Array.from(fresh.querySelectorAll('details[data-open-key]'))
            .find((node) => node.dataset.openKey === section.dataset.openKey);

        /* Oturum düşmüşse giriş sayfası gelir; bölüm yoksa bağlantı kalır. */
        if (! response.ok || ! loaded || loaded.querySelector(HOLDER)) {
            throw new Error('Bölüm yüklenemedi');
        }

        holder.replaceWith(...Array.from(loaded.childNodes).filter((node) => node.nodeName !== 'SUMMARY'));
        document.dispatchEvent(new CustomEvent('eys:fragment', { detail: section }));
    } catch {
        link.textContent = text;
        holder.removeAttribute('aria-busy');
        delete holder.dataset.loading;
    }
}

export function init() {
    /* toggle kabarmaz; yakalama evresinde dinlenir. */
    document.addEventListener('toggle', (event) => {
        if (event.target instanceof HTMLDetailsElement && event.target.open) {
            load(event.target);
        }
    }, true);
}
