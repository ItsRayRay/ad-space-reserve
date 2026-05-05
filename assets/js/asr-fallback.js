/**
 * AdShimmer - Fallback Detection Script
 *
 * Monitors ad slot containers and shows fallback images
 * when ads fail to load within the configured timeout.
 *
 * Auto-generated code should be deployed via the plugin.
 * This script runs on the frontend.
 */

(function() {
    'use strict';

    /**
     * Check if an ad slot container has loaded content.
     *
     * Considers an ad "loaded" if the container has:
     * - An iframe (common for ad serving)
     * - An img with src (not our fallback image)
     * - Content with substantial height
     *
     * @param {HTMLElement} container The ad slot container.
     * @return {boolean} True if ad appears loaded.
     */
    function hasAdContent(container) {
        // Check for iframes (most ad networks use iframes)
        if (container.querySelector('iframe')) {
            return true;
        }

        // Check for images that aren't our fallback
        var images = container.querySelectorAll('img:not(.asr-fallback-img)');
        for (var i = 0; i < images.length; i++) {
            if (images[i].src && images[i].src !== '') {
                return true;
            }
        }

        // Check for any ad-network specific elements
        if (container.querySelector('[id*="google_ads"], [class*="ad-"]')) {
            return true;
        }

        return false;
    }

    /**
     * Show the fallback image for a container.
     *
     * @param {HTMLElement} container The ad slot container.
     */
    function showFallback(container) {
        var fallbackImg = container.querySelector('.asr-fallback-img');

        if (!fallbackImg) {
            return;
        }

        // Show the fallback image (it's pre-rendered with src, just hidden)
        fallbackImg.style.display = 'block';
        container.classList.add('asr-fallback-visible');
    }

    /**
     * Initialize fallback monitoring for a container.
     *
     * @param {HTMLElement} container The ad slot container.
     */
    function initFallback(container) {
        var timeout = parseInt(container.getAttribute('data-asr-fallback-timeout'), 10) || 2000;

        // Clamp timeout to valid range
        timeout = Math.max(1000, Math.min(30000, timeout));

        // Set up MutationObserver to detect ad load
        var observer = new MutationObserver(function(mutations) {
            if (hasAdContent(container)) {
                observer.disconnect();
                clearTimeout(timeoutId);
            }
        });

        observer.observe(container, {
            childList: true,
            subtree: true,
            attributes: true
        });

        // Set timeout for fallback
        var timeoutId = setTimeout(function() {
            observer.disconnect();

            // Final check before showing fallback
            if (!hasAdContent(container)) {
                showFallback(container);
            }
        }, timeout);

        // Also check immediately in case ad is already loaded
        if (hasAdContent(container)) {
            observer.disconnect();
            clearTimeout(timeoutId);
        }
    }

    /**
     * Initialize fallback detection on page load.
     */
    function init() {
        var containers = document.querySelectorAll('.asr-fallback-container');

        for (var i = 0; i < containers.length; i++) {
            initFallback(containers[i]);
        }
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
