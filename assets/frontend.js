(function () {
    'use strict';

    function initializeAnnouncementBars() {
        document.querySelectorAll('[data-hometown-announcement]').forEach(function (bar) {
            const close = bar.querySelector('[data-hometown-announcement-close]');
            if (!close || bar.dataset.dismissReady) return;
            bar.dataset.dismissReady = '1';
            const key = 'hometown-announcement-dismissed:' + bar.dataset.hometownAnnouncement;
            const preview = bar.dataset.hometownAnnouncementPreview === '1';

            function removeBar() {
                bar.remove();
                window.dispatchEvent(new Event('resize'));
            }

            try {
                if (!preview && window.sessionStorage.getItem(key) === '1') {
                    removeBar();
                    return;
                }
            } catch (error) { /* Closing still works when browser storage is unavailable. */ }

            close.addEventListener('click', function () {
                try {
                    if (!preview) window.sessionStorage.setItem(key, '1');
                } catch (error) { /* Storage is optional. */ }
                removeBar();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAnnouncementBars);
    } else {
        initializeAnnouncementBars();
    }
}());
