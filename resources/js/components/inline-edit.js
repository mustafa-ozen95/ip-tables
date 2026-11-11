import { $, on } from '../lib/dom.js';

/**
 * Listede ada ya da açıklamaya çift tıklayınca yerinde düzenleme.
 *
 * Alanın yerine küçük bir form açılır: kutu, ✓ (kaydet) ve ✕ (vazgeç).
 * Form sıradan bir PATCH formudur; kaydet önce onay penceresinden geçer
 * ("A" → "B", bkz. confirm.js), sonra sayfa yenilenmeden gönderilir
 * (data-inline). Sunucu listeye döner; açık bloklar açık kalır (bkz.
 * navigation.js · reopen). Yetki ve doğrulama sunucuda; burada yalnızca
 * yetkisi olana çizilmiş alanlar (data-inline-edit) dinlenir.
 *
 * Enter kaydeder, Esc vazgeçer. Aynı anda tek alan düzenlenir.
 */

const FIELD = '[data-inline-edit]';

/** Düzenlenen alan ve eski içeriği; vazgeçince geri konur. */
let editing = null;

function quote(value) {
    return value === '' ? '(boş)' : `“${value}”`;
}

function hidden(name, value) {
    const input = document.createElement('input');

    input.type = 'hidden';
    input.name = name;
    input.value = value;

    return input;
}

function iconButton(type, text, label, variant) {
    const button = document.createElement('button');

    button.type = type;
    button.className = `btn btn--sm btn--icon ${variant}`;
    button.textContent = text;
    button.title = label;
    button.setAttribute('aria-label', label);

    return button;
}

function cancel() {
    if (editing === null) {
        return;
    }

    editing.field.replaceChildren(...editing.children);
    editing = null;
}

function open(field) {
    if (editing?.field === field) {
        return;
    }

    cancel();

    const old = field.dataset.inlineValue ?? '';
    const label = field.dataset.inlineLabel;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = field.dataset.inlineUrl;
    form.className = 'inline-edit';
    form.dataset.inline = '';
    form.dataset.confirmTitle = 'Değişiklik kaydedilsin mi?';
    form.dataset.confirmReasonless = '';

    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'value';
    input.value = old;
    input.className = 'input';
    input.maxLength = Number(field.dataset.inlineMax) || 1000;
    input.setAttribute('aria-label', label);

    form.append(
        hidden('_token', $('meta[name="csrf-token"]')?.content ?? ''),
        hidden('_method', 'PATCH'),
        hidden('field', field.dataset.inlineEdit),
        input,
        iconButton('submit', '✓', 'Kaydet', 'btn--primary'),
        iconButton('button', '✕', 'Vazgeç', 'btn--ghost'),
    );

    /* Onay penceresi gönderim anındaki metni gösterir (bkz. confirm.js · open). */
    const describe = () => {
        form.dataset.confirm = `${label}: ${quote(old)} → ${quote(input.value.trim())}`;
    };
    describe();
    input.addEventListener('input', describe);

    editing = { field, children: [...field.childNodes] };
    field.replaceChildren(form);

    input.focus();
    input.select();
}

export function init() {
    on('dblclick', FIELD, (event, field) => {
        if (event.target.closest('.inline-edit')) {
            return;
        }

        event.preventDefault();
        open(field);
    });

    /*
     * Açıklaması olmayan parçada açıklama satırı boştur ve yer kaplamaz;
     * hücrenin boş yerine çift tıklamak onu açar.
     */
    on('dblclick', '[data-inline-cell]', (event, cell) => {
        if (event.target !== cell) {
            return;
        }

        const note = cell.querySelector('[data-inline-edit="description"]');

        if (note) {
            event.preventDefault();
            open(note);
        }
    });

    on('click', '.inline-edit button[type="button"]', cancel);

    on('keydown', '.inline-edit input', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            cancel();
        }
    });

    /* Sayfa yeniden çizilince düzenleme de biter. */
    document.addEventListener('eys:page', () => {
        editing = null;
    });
}
