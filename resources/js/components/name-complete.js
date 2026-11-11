import { on } from '../lib/dom.js';

/**
 * Ad kutusunda Excel gibi tamamlama.
 *
 * Yazdıkça, yazılanla başlayan ilk mevcut ad kutuya tamamlanır; eklenen
 * kısım seçili gelir: yazmaya devam etmek onu ezer, Enter kabul eder,
 * Sil/Geri onu atar. Açılan liste tarayıcının (datalist) kendi
 * önerisidir; ikisi aynı adlardan beslenir.
 *
 * Adlar sayfaya gömülmez (kapalı bölümün, aramada elenen kaydın adı HTML'e
 * çıkmasın); ad kutusu ilk açılınca uçtan bir kez alınır (bkz.
 * AddressNameController, yalnız görülebilen kayıtların adları). Sayfa
 * yeniden çizilince (ör. ad kaydedilince) yeniden alınır.
 *
 * Yalnız harf yazınca tamamlanır: silerken ya da listeden seçerken
 * araya girilmez, yoksa "ae1" seçen kişiye "ae10" dayatılırdı.
 */

const LIST = 'name-suggestions';

/** Alınan adlar; istek sürerken söz. */
let loading = null;
let names = [];

function list() {
    return document.getElementById(LIST);
}

function load() {
    const source = list()?.dataset.source;

    if (loading !== null || ! source) {
        return;
    }

    loading = fetch(source, {
        headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' },
        credentials: 'same-origin',
    })
        .then((response) => (response.ok ? response.json() : []))
        .then((loaded) => {
            names = Array.isArray(loaded) ? loaded : [];
            list()?.replaceChildren(...names.map((name) => Object.assign(document.createElement('option'), { value: name })));
        })
        .catch(() => { loading = null; });
}

function complete(event, input) {
    const typed = input.value;

    if (event.inputType !== 'insertText' || typed === '' || input.selectionStart !== typed.length) {
        return;
    }

    const lower = typed.toLocaleLowerCase('tr');
    const match = names.find((name) => name.length > typed.length && name.toLocaleLowerCase('tr').startsWith(lower));

    if (match === undefined) {
        return;
    }

    input.value = match;
    input.setSelectionRange(typed.length, match.length);

    /* Değeri dinleyen (yerinde düzenlemenin onay metni) tamamlanmışı görsün. */
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

export function init() {
    on('focusin', `input[list="${LIST}"]`, load);
    on('input', `input[list="${LIST}"]`, complete);

    document.addEventListener('eys:page', () => {
        loading = null;
        names = [];
    });
}
