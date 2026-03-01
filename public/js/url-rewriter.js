/**
 * Cloud Storage URL Rewriter
 *
 * Rewrites core document.send.php URLs to the plugin's endpoint,
 * so documents stored in cloud storage are served correctly.
 *
 * Watches for dynamically added elements (e.g. rich-text editor previews)
 * using a MutationObserver on childList only (not attributes) to avoid loops.
 */
(function () {
    'use strict';

    var PLUGIN_MARKER = '/plugins/cloudstorage/';
    var CORE_SEND = '/front/document.send.php';

    // Derive plugin base URL from this script's own src
    var PLUGIN_BASE = (function () {
        var scriptSrc = document.currentScript ? document.currentScript.src : '';
        var idx = scriptSrc.indexOf('/plugins/');
        if (idx !== -1) {
            return scriptSrc.substring(0, idx) + '/plugins/cloudstorage/front/document.send.php';
        }
        return '/plugins/cloudstorage/front/document.send.php';
    })();

    /**
     * Check if a URL needs rewriting (core document.send.php → plugin endpoint).
     */
    function needsRewrite(url) {
        if (!url) return false;
        // Already points to plugin — skip
        if (url.indexOf(PLUGIN_MARKER) !== -1) return false;
        // Must contain the core endpoint
        if (url.indexOf(CORE_SEND) === -1) return false;
        // Must have a recognized query parameter
        return url.indexOf('document.send.php?docid=') !== -1
            || url.indexOf('document.send.php?file=') !== -1;
    }

    /**
     * Rewrite a core document URL to the plugin endpoint.
     */
    function rewriteUrl(url) {
        var idx = url.indexOf('document.send.php');
        var queryPart = url.substring(idx + 'document.send.php'.length);
        return PLUGIN_BASE + queryPart;
    }

    /**
     * Process a single element, rewriting its relevant attribute if needed.
     */
    function processElement(el) {
        var attr;
        switch (el.tagName) {
            case 'A':      attr = 'href'; break;
            case 'IMG':    attr = 'src';  break;
            case 'OBJECT': attr = 'data'; break;
            case 'EMBED':  attr = 'src';  break;
            default: return;
        }

        var val = el.getAttribute(attr);
        if (needsRewrite(val)) {
            el.setAttribute(attr, rewriteUrl(val));
        }
    }

    /**
     * Scan a DOM subtree for elements that need URL rewriting.
     */
    function scanAndRewrite(root) {
        var elements = root.querySelectorAll(
            'a[href*="document.send.php?"], img[src*="document.send.php?"], '
            + 'object[data*="document.send.php?"], embed[src*="document.send.php?"]'
        );
        for (var i = 0; i < elements.length; i++) {
            processElement(elements[i]);
        }
    }

    // Initial scan
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scanAndRewrite(document); });
    } else {
        scanAndRewrite(document);
    }

    // Watch for dynamically added nodes (childList only — NOT attributes to avoid loops)
    var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var added = mutations[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
                var node = added[j];
                if (node.nodeType === Node.ELEMENT_NODE) {
                    processElement(node);
                    scanAndRewrite(node);
                }
            }
        }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
