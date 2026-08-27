/**
 * Marking the user's own spot on the tarmac map (spec 6.5, 11.4).
 *
 * The drawing is inlined, and every position in it is an element whose id is
 * that position's map_ref. All this does is put a class on his element and a
 * second class on his group's, so app.css can colour them — his own in the
 * accent, the rest of his group in a secondary colour, exactly as 11.4
 * describes.
 *
 * Done here rather than server-side because the page's CSP is style-src 'self'
 * (Response::send), so an injected inline <style> would be blocked outright.
 * An external stylesheet cannot name an id it does not know at build time, and
 * a class it can.
 */
(function () {
    'use strict';

    var map = document.querySelector('[data-map]');
    if (!map) {
        return;
    }

    /**
     * Look the element up by id, within the map only.
     *
     * getElementById would search the whole document and could land on some
     * unrelated element that happens to share the name. The refs are already
     * filtered server-side to a safe character set (Resm\TarmacMap::ref), so
     * this cannot be handed a selector that breaks the query.
     */
    function mark(ref, className) {
        if (!ref) {
            return false;
        }

        var el = map.querySelector('#' + ref);
        if (!el) {
            // A map_ref naming an element the drawing does not have. Says
            // nothing on screen: a missing highlight is a position somebody
            // has to ask about, which is the situation without a map at all.
            return false;
        }

        el.classList.add(className);
        return true;
    }

    var mates = (map.getAttribute('data-mates') || '').split(',');
    for (var i = 0; i < mates.length; i++) {
        mark(mates[i].trim(), 'map__mate');
    }

    // His own last, so that if he somehow appears in both lists the class that
    // wins is the one that says "you are here".
    var me = map.getAttribute('data-me');
    if (mark(me, 'map__me')) {
        var el = map.querySelector('#' + me);
        if (el && el.scrollIntoView) {
            // A tarmac drawing is wider than a phone. Landing on his own spot
            // saves a man in gloves panning around to find it.
            el.scrollIntoView({ block: 'center', inline: 'center' });
        }
    }
}());
