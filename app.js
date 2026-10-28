/* Small behaviours shared by every page. Each one is optional: pages work without it. */
(function () {
    'use strict';

    // Press "/" to jump to the page's search box.
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        var tag = document.activeElement && document.activeElement.tagName;
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) return;
        var box = document.querySelector('[data-search-input]') || document.querySelector('[data-global-search]');
        if (box) { e.preventDefault(); box.focus(); box.select(); }
    });

    // Success messages tidy themselves away; errors stay until the page changes.
    Array.prototype.forEach.call(document.querySelectorAll('.message'), function (m) {
        setTimeout(function () { m.hidden = true; }, 8000);
    });

    // A link like products.php#add-product opens the collapsed panel it points at.
    function openFromHash() {
        var id = location.hash.slice(1);
        var el = id && document.getElementById(id);
        if (el && el.tagName === 'DETAILS') {
            el.open = true;
            el.scrollIntoView();
            var first = el.querySelector('input, select');
            if (first) first.focus({ preventScroll: true });
        }
    }
    window.addEventListener('hashchange', openFromHash);
    openFromHash();
})();