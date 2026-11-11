import { on } from '../lib/dom.js';
import { http } from '../lib/http.js';
import { emit } from './toast.js';

/**
 * ↻ şimdi kontrol et: ping kuyruğa atılır, ikon sonuç gelene dek döner;
 * sonuç gelince nokta sayfa yenilenmeden güncellenir.
 *
 * Ping kuyruktan gider (bkz. GatewayPingController): sonuç hemen dönmez,
 * kayıttaki son kontrol zamanı değişene dek aralıkla sorulur. Kuyruk
 * yoğunsa bir süre sonra bırakılır; sonuç sayfa yenilenince görünür.
 *
 *   <span data-ping>
 *       <span data-ping-dot hidden><span class="dot"></span><span data-ping-label></span></span>
 *       <form data-ping-now data-ping-status="{{ route(...) }}">…</form>
 *   </span>
 */

const POLL_MS = 1500;
const GIVE_UP_MS = 30000;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function show(box, result) {
    const dot = box.querySelector('[data-ping-dot]');

    dot.hidden = false;
    dot.title = result.label;
    dot.querySelector('.dot').className = `dot dot--${result.variant}`;
    dot.querySelector('[data-ping-label]').textContent = result.label;
}

async function waitForResult(form, before) {
    for (const started = Date.now(); Date.now() - started < GIVE_UP_MS;) {
        await wait(POLL_MS);

        const result = await http.get(form.dataset.pingStatus, { silent: true }).catch(() => null);

        if (result?.checkedAt && result.checkedAt !== before) {
            return result;
        }
    }

    return null;
}

async function check(form) {
    const button = form.querySelector('button');

    if (button.getAttribute('aria-busy') === 'true') {
        return;
    }

    button.setAttribute('aria-busy', 'true');

    try {
        const { checkedAt } = await http.post(form.action);
        const result = await waitForResult(form, checkedAt);

        if (result) {
            show(form.closest('[data-ping]'), result);
        } else {
            emit('Ping sonucu henüz gelmedi; kuyruk yoğun olabilir. Sayfayı biraz sonra yenileyin.', 'info');
        }
    } catch (error) {
        /* 422 (gateway yok) çağırana kalır; ötekileri http zaten bildirdi. */
        if (error.status === 422) {
            emit(error.message, 'danger');
        }
    } finally {
        button.removeAttribute('aria-busy');
    }
}

export function init() {
    on('submit', 'form[data-ping-now]', (event, form) => {
        event.preventDefault();
        check(form);
    });
}
