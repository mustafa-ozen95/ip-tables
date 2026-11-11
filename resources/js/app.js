import * as columns from './components/columns.js';
import * as confirmDialog from './components/confirm.js';
import * as copy from './components/copy.js';
import * as dialog from './components/dialog.js';
import * as gatewayPing from './components/gateway-ping.js';
import * as keyboard from './components/keyboard.js';
import * as liveSearch from './components/live-search.js';
import * as conditionalField from './components/conditional-field.js';
import * as userMenu from './components/user-menu.js';
import * as navigation from './components/navigation.js';
import * as sessionLock from './components/session-lock.js';
import * as ordering from './components/sorting.js';
import * as theme from './components/theme.js';
import * as toast from './components/toast.js';

/**
 * Uygulama girişi.
 *
 * Buradaki modüller yalnızca DOM, olay ve arayüz işleriyle ilgilenir.
 * Yetki ve doğrulama sunucuda yapılır; burada tekrar edilmez.
 *
 * İKİ AYRI KURULUM var, çünkü sayfa geçişleri tarayıcıyı yenilemiyor
 * (bkz. navigation.js):
 *
 *  - BİR KEZ: olayları document üzerinde dinleyen bileşenler. İkinci kez
 *    kurulsalardı her tıklama iki kez işlenirdi.
 *  - HER ÇİZİMDE: düğümün kendisine bakan işler (yeni gelen içerikteki
 *    bildirim, satırların odaklanabilirliği).
 *
 * navigation EN SONA kurulur: bağlantıyı önce pencere ve onay bileşenleri
 * görmeli, geçiş yalnızca onlar karışmadıysa devralınmalı.
 */
theme.init();

function everyRender() {
    dialog.openPending();
    toast.init();
    keyboard.prepare();

    /* Dar ekranda şerit doğru bölmeye iner; geniş ekranda hiçbir şey yapmaz. */
    columns.init();

    /* Kayıt formundaki koşullu alanlar da her çizimde yeni gelen formda. */
    conditionalField.init();

    /* Canlı aramada yeniden çizilen kutuya odak ve imleç (bkz. live-search.js). */
    liveSearch.restore();
}

document.addEventListener('DOMContentLoaded', () => {
    dialog.init();
    confirmDialog.init();
    copy.init();
    ordering.init();
    gatewayPing.init();
    keyboard.init();
    liveSearch.init();
    userMenu.init();
    navigation.init();
    /* Ekran kilidi: hareketsiz sekme oturumu kapatır (bkz. session-lock.js). Bir kez. */
    sessionLock.init();

    everyRender();
});

document.addEventListener('eys:page', everyRender);
