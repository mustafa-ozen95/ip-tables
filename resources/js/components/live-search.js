import { $, on } from '../lib/dom.js';
import { searchAsTyped } from './navigation.js';

/**
 * Liste aramaları yazdıkça süzer.
 *
 * Süzme sunucuda yapılır (aynı arama kuralı, aynı yetki daraltması); burada
 * yalnızca form kullanıcı durduğunda gönderilir. Her harfte istek atılsaydı
 * hızlı yazan biri onlarca istek başlatırdı.
 *
 * Gövde yeniden çizildiği için kutu da yeniden çizilir: odak ve imleç
 * kaybolur, istek sürerken yazılan harfler sunucunun eski değeriyle
 * ezilirdi. Çizimden sonra kutuya son yazılan değer ve imleç geri konur;
 * değer sunucunun gördüğünden farklıysa bekleyen gönderim onu da süzer.
 *
 * Enter ve Filtrele düğmesi eskisi gibi çalışır (JS'siz de).
 */

const DELAY = 250;

const FIELD = 'form[method="GET" i] .filter__input';

let timer = null;

/** Yazılan son durum; yalnız canlı aramanın açtığı çizimde geri konur. */
let typed = null;

export function init() {
    on('input', FIELD, (event, input) => {
        typed = {
            name: input.name,
            value: input.value,
            start: input.selectionStart,
            end: input.selectionEnd,
        };

        clearTimeout(timer);
        timer = setTimeout(() => searchAsTyped(input.form), DELAY);
    });

    /* Enter ile gönderilen arama beklemez; bekleyen gönderim ikinci kez gitmesin. */
    on('submit', 'form', () => clearTimeout(timer));

    /*
     * Kutudan başka bir öğeye geçen ya da bir bağlantıya tıklayan kişi
     * aramayı bıraktı; sonraki çizim odağı ondan çalmaz. Gövde değişirken
     * kutu silinince de focusout gelir, ama o zaman geçilen öğe yoktur.
     */
    on('focusout', FIELD, (event) => {
        if (event.relatedTarget) {
            typed = null;
        }
    });
    on('click', 'a[href]', () => {
        typed = null;
    });
}

/** Her çizimden sonra: kutuyu yazılan değer ve imleçle geri verir. */
export function restore() {
    if (typed === null) {
        return;
    }

    const input = $(`${FIELD}[name="${CSS.escape(typed.name)}"]`);

    if (! input) {
        typed = null;

        return;
    }

    input.value = typed.value;
    input.focus();
    input.setSelectionRange(typed.start, typed.end);
}
