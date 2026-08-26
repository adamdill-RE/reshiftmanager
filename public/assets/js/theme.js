/**
 * Theme and Large Text (spec 9.2, 9.3).
 *
 * Two attributes on the root element, both of which the stylesheet has carried
 * since phase 1 and neither of which anything set until now:
 *
 *     data-theme="dark" | "light"     pinned; absent means follow the device
 *     data-text="large"               absent means the 17px base
 *
 * Loaded in <head> and deliberately NOT deferred. Everything else in this app
 * is, but a theme applied after first paint is a white flash — and the person
 * it flashes at is standing on a dark tarmac at 02:00 with their night vision
 * to lose (spec 9.2). It has to run before the first paint or it is worse than
 * not running at all, which is why it is small enough to be worth the block.
 *
 * The preference lives in localStorage. CLAUDE.md forbids localStorage for
 * AUTH state, and this is not that: nothing here is a credential, nothing here
 * is trusted by the server, and the worst a tampered value can do is make
 * somebody's own phone dark. Storing it server-side would mean a round trip
 * before paint, which is the one thing this file cannot afford.
 */
(function () {
    'use strict';

    var THEME_KEY = 'resm.theme';   // 'dark' | 'light' | 'auto'
    var TEXT_KEY = 'resm.text';     // 'large' | 'normal'

    /**
     * Private browsing, a storage quota, and a browser set to block site data
     * all throw on access rather than returning null. A preference that cannot
     * be read is not an error worth showing anybody — the device default is a
     * perfectly good answer.
     */
    function read(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function write(key, value) {
        try {
            if (value === null) {
                window.localStorage.removeItem(key);
            } else {
                window.localStorage.setItem(key, value);
            }
            return true;
        } catch (e) {
            return false;
        }
    }

    function applyTheme(choice) {
        var root = document.documentElement;

        if (choice === 'dark' || choice === 'light') {
            root.setAttribute('data-theme', choice);
        } else {
            // Auto. The attribute is removed rather than set to anything: the
            // stylesheet's prefers-color-scheme block is what follows the
            // device, and it is written to lose to an explicit data-theme.
            root.removeAttribute('data-theme');
        }
    }

    function applyText(choice) {
        var root = document.documentElement;

        if (choice === 'large') {
            root.setAttribute('data-text', 'large');
        } else {
            root.removeAttribute('data-text');
        }
    }

    var theme = read(THEME_KEY) || 'auto';
    var text = read(TEXT_KEY) || 'normal';

    applyTheme(theme);
    applyText(text);

    window.Resm = window.Resm || {};
    window.Resm.display = {
        theme: function () { return theme; },
        text: function () { return text; },

        setTheme: function (choice) {
            if (choice !== 'dark' && choice !== 'light') {
                choice = 'auto';
            }
            theme = choice;
            applyTheme(choice);

            return write(THEME_KEY, choice === 'auto' ? null : choice);
        },

        setText: function (choice) {
            text = choice === 'large' ? 'large' : 'normal';
            applyText(text);

            return write(TEXT_KEY, text === 'large' ? 'large' : null);
        }
    };
}());
