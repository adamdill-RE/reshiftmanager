/**
 * "Install this app on your phone", platform-aware (spec 6.7).
 *
 * The page renders every platform's instructions and this hides the ones that
 * do not apply. That direction matters: if this file fails to load, or the
 * phone is one nothing here recognises, the man in the hangar is left looking
 * at all three sets and can follow the one he recognises. The other way round
 * he would be left looking at nothing.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-install]');
    if (!root) {
        return;
    }

    var done = root.querySelector('[data-install-done]');
    var promptBox = root.querySelector('[data-install-prompt]');
    var promptButton = root.querySelector('[data-install-go]');

    function show(el, on) {
        if (el) {
            el.hidden = !on;
        }
    }

    /**
     * Already running installed?
     *
     * display-mode covers Android and desktop; navigator.standalone is the
     * only signal iOS gives, and it exists nowhere else.
     */
    function installed() {
        try {
            if (window.matchMedia('(display-mode: standalone)').matches) {
                return true;
            }
        } catch (e) { /* an old browser without matchMedia is not installed */ }

        return window.navigator.standalone === true;
    }

    /**
     * Which set of instructions to keep.
     *
     * User-agent sniffing, which is normally the wrong tool — but the question
     * here is genuinely "which physical buttons is this person looking at", and
     * no feature test answers that. Getting it wrong shows the wrong steps; it
     * does not break anything, which is why it is acceptable here and would not
     * be for a capability.
     */
    function platform() {
        var ua = navigator.userAgent || '';

        // iPadOS 13+ reports itself as a Mac. The touch-point count is what
        // still separates them, and an iPad is the one Mac with a Share sheet.
        var iPadOS = /Macintosh/.test(ua) && navigator.maxTouchPoints > 1;

        if (/iPhone|iPad|iPod/.test(ua) || iPadOS) {
            return 'ios';
        }
        if (/Android/.test(ua)) {
            return 'android';
        }

        return 'desktop';
    }

    var here = platform();
    var sections = root.querySelectorAll('[data-platform]');

    for (var i = 0; i < sections.length; i++) {
        sections[i].hidden = sections[i].getAttribute('data-platform') !== here;
    }

    if (installed()) {
        show(done, true);
        for (var j = 0; j < sections.length; j++) {
            sections[j].hidden = true;
        }
        return;
    }

    /**
     * Chrome and Edge offer to do it directly. When they do, the button is
     * better than any set of written steps — so the steps stay rendered
     * underneath it rather than being replaced, because the event does not
     * fire on every visit and a man who came back for the instructions should
     * still find them.
     */
    var deferred = null;

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferred = event;
        show(promptBox, true);
    });

    if (promptButton) {
        promptButton.addEventListener('click', function () {
            if (!deferred) {
                return;
            }

            deferred.prompt();
            deferred.userChoice.then(function () {
                // Single-use: the event cannot be prompted twice, and a button
                // that silently does nothing on the second tap is worse than
                // one that has gone away.
                deferred = null;
                show(promptBox, false);
            });
        });
    }

    window.addEventListener('appinstalled', function () {
        deferred = null;
        show(promptBox, false);
        show(done, true);
        for (var k = 0; k < sections.length; k++) {
            sections[k].hidden = true;
        }
    });
}());
