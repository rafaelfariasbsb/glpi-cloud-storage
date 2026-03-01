/**
 * Azure Blob Storage for GLPI - URL Rewriter
 *
 * Rewrites document download URLs from the core endpoint
 * (/front/document.send.php?docid=X) to the plugin endpoint
 * (/plugins/azureblobstorage/front/document.send.php?docid=X).
 *
 * Only rewrites URLs with ?docid= parameter.
 * Never rewrites URLs with ?file= parameter (pictures, inventories).
 *
 * @license GPL-3.0-or-later
 */
(function () {
    'use strict';

    const CORE_PATTERN = /\/front\/document\.send\.php\?docid=/;

    // Derive base path dynamically from this script's own URL to support
    // GLPI installations in subdirectories (e.g., /glpi/plugins/...)
    const PLUGIN_BASE = (function () {
        const scripts = document.querySelectorAll('script[src*="azureblobstorage"]');
        for (const s of scripts) {
            const src = s.getAttribute('src') || '';
            const idx = src.indexOf('/plugins/azureblobstorage/');
            if (idx !== -1) {
                return src.substring(0, idx) + '/plugins/azureblobstorage/front/document.send.php';
            }
        }
        return '/plugins/azureblobstorage/front/document.send.php';
    })();

    /**
     * Rewrite a single URL string if it matches the core document pattern.
     *
     * @param {string} url Original URL
     * @returns {string} Rewritten URL or original if no match
     */
    function rewriteUrl(url) {
        if (!url || !CORE_PATTERN.test(url)) {
            return url;
        }

        // Extract query string (everything after document.send.php)
        const idx = url.indexOf('document.send.php');
        if (idx === -1) {
            return url;
        }

        const queryPart = url.substring(idx + 'document.send.php'.length);
        return PLUGIN_BASE + queryPart;
    }

    /**
     * Process a single DOM element, rewriting relevant URLs.
     *
     * @param {Element} element
     */
    function processElement(element) {
        // Links (a[href])
        if (element.tagName === 'A' && element.href) {
            const href = element.getAttribute('href');
            if (href && CORE_PATTERN.test(href)) {
                element.setAttribute('href', rewriteUrl(href));
            }
        }

        // Images (img[src]) - inline images in rich text
        if (element.tagName === 'IMG' && element.src) {
            const src = element.getAttribute('src');
            if (src && CORE_PATTERN.test(src)) {
                element.setAttribute('src', rewriteUrl(src));
            }
        }

        // Objects/Embeds (object[data], embed[src])
        if (element.tagName === 'OBJECT' && element.data) {
            const data = element.getAttribute('data');
            if (data && CORE_PATTERN.test(data)) {
                element.setAttribute('data', rewriteUrl(data));
            }
        }

        if (element.tagName === 'EMBED' && element.src) {
            const src = element.getAttribute('src');
            if (src && CORE_PATTERN.test(src)) {
                element.setAttribute('src', rewriteUrl(src));
            }
        }
    }

    /**
     * Scan and rewrite all matching elements in a subtree.
     *
     * @param {Element|Document} root
     */
    function scanAndRewrite(root) {
        const selectors = [
            'a[href*="document.send.php?docid="]',
            'img[src*="document.send.php?docid="]',
            'object[data*="document.send.php?docid="]',
            'embed[src*="document.send.php?docid="]',
        ];

        const elements = root.querySelectorAll(selectors.join(', '));
        elements.forEach(processElement);
    }

    // Initial scan when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            scanAndRewrite(document);
        });
    } else {
        scanAndRewrite(document);
    }

    // MutationObserver for dynamically loaded content (AJAX, timeline, etc.)
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            // Process added nodes
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    processElement(node);
                    scanAndRewrite(node);
                }
            });

            // Process attribute changes on the target itself
            if (mutation.type === 'attributes' && mutation.target.nodeType === Node.ELEMENT_NODE) {
                processElement(mutation.target);
            }
        });
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['href', 'src', 'data'],
    });
})();
