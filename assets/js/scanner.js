/**
 * Ad Space Reserve - Frontend Scanner
 *
 * Detects R89 ad wrappers injected after DOM load using MutationObserver.
 * Sends detected slot data to WordPress admin via AJAX.
 */
(function() {
    'use strict';

    // Only run if scanner config is available and user is admin
    if (typeof asrScanner === 'undefined' || !asrScanner.isAdmin) {
        return;
    }

    const ASR_Scanner = {
        detectedSlots: [],
        observer: null,
        debounceTimer: null,
        scanIndicator: null,

        /**
         * Initialize the scanner
         */
        init: function() {
            this.createScanIndicator();
            this.startObserver();
            this.scanExistingWrappers();

            // Send data before page unload
            window.addEventListener('beforeunload', () => this.sendData());

            // Also send data periodically
            setInterval(() => this.sendData(), 10000);
        },

        /**
         * Create visual indicator that scan mode is active
         */
        createScanIndicator: function() {
            this.scanIndicator = document.createElement('div');
            this.scanIndicator.id = 'asr-scan-indicator';
            this.scanIndicator.innerHTML = `
                <div style="
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    background: #2271b1;
                    color: white;
                    padding: 12px 20px;
                    border-radius: 6px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    font-size: 13px;
                    z-index: 999999;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                    display: flex;
                    align-items: center;
                    gap: 10px;
                ">
                    <span style="
                        width: 10px;
                        height: 10px;
                        background: #46b450;
                        border-radius: 50%;
                        animation: asr-pulse 1.5s infinite;
                    "></span>
                    <span>ASR Scan Mode Active</span>
                    <span id="asr-slot-count" style="
                        background: rgba(255,255,255,0.2);
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-size: 11px;
                    ">0 slots</span>
                </div>
                <style>
                    @keyframes asr-pulse {
                        0%, 100% { opacity: 1; }
                        50% { opacity: 0.5; }
                    }
                </style>
            `;
            document.body.appendChild(this.scanIndicator);
        },

        /**
         * Update the slot count in indicator
         */
        updateIndicator: function() {
            const countEl = document.getElementById('asr-slot-count');
            if (countEl) {
                const count = this.detectedSlots.length;
                countEl.textContent = count + (count === 1 ? ' slot' : ' slots');
            }
        },

        /**
         * Start MutationObserver to watch for new R89 wrappers
         */
        startObserver: function() {
            this.observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            this.checkForWrapper(node);
                            // Also check descendants
                            if (node.querySelectorAll) {
                                node.querySelectorAll('[id*="r89"][id*="wrapper"]').forEach(
                                    (el) => this.checkForWrapper(el)
                                );
                            }
                        }
                    });
                });
            });

            this.observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        },

        /**
         * Scan for any R89 wrappers that already exist
         */
        scanExistingWrappers: function() {
            document.querySelectorAll('[id*="r89"][id*="wrapper"]').forEach(
                (el) => this.checkForWrapper(el)
            );
        },

        /**
         * Check if element is an R89 wrapper and extract data
         */
        checkForWrapper: function(element) {
            if (!element.id || !element.id.includes('r89') || !element.id.includes('wrapper')) {
                return;
            }

            // Skip if already detected
            if (this.detectedSlots.find(s => s.wrapperId === element.id)) {
                return;
            }

            const slotData = this.extractSlotData(element);
            if (slotData) {
                this.detectedSlots.push(slotData);
                this.highlightWrapper(element);
                this.updateIndicator();
                this.debouncedSend();
            }
        },

        /**
         * Extract slot data from wrapper element
         */
        extractSlotData: function(element) {
            const wrapperId = element.id;
            const parent = element.parentElement;

            // Parse wrapper ID: r89-{device}-{slot-type}-{index}-wrapper
            // Examples: r89-desktop-billboard-btf-0-wrapper, r89-mobile-rectangle-mid-2-0-wrapper
            const idParts = wrapperId.replace('-wrapper', '').split('-');

            // Extract device (desktop/mobile)
            let device = 'unknown';
            let slotType = 'unknown';

            if (idParts[1] === 'desktop' || idParts[1] === 'Desktop') {
                device = 'desktop';
                // Join remaining parts except last (index) for slot type
                slotType = idParts.slice(2, -1).join('-');
            } else if (idParts[1] === 'mobile' || idParts[1] === 'Mobile') {
                device = 'mobile';
                slotType = idParts.slice(2, -1).join('-');
            }

            // Get rendered dimensions (wait a bit for ad to load)
            const rect = element.getBoundingClientRect();

            // Build parent selector
            const parentSelector = this.buildSelector(parent);

            // Get position among siblings
            const position = this.getPositionIndex(element);

            return {
                wrapperId: wrapperId,
                device: device,
                slotType: slotType,
                parentSelector: parentSelector,
                position: position,
                renderedHeight: Math.round(rect.height),
                renderedWidth: Math.round(rect.width),
                pageUrl: window.location.pathname,
                pageType: this.detectPageType(),
                timestamp: new Date().toISOString()
            };
        },

        /**
         * Build a CSS selector for an element
         */
        buildSelector: function(element) {
            if (!element || element === document.body) {
                return 'body';
            }

            // Try ID first
            if (element.id && !element.id.includes('r89')) {
                return '#' + CSS.escape(element.id);
            }

            // Try meaningful classes
            const classes = Array.from(element.classList)
                .filter(c => !c.includes('r89') && c.length < 50);

            if (classes.length > 0) {
                const selector = '.' + classes.slice(0, 2).map(c => CSS.escape(c)).join('.');
                // Verify uniqueness
                if (document.querySelectorAll(selector).length === 1) {
                    return selector;
                }
            }

            // Build path from parent
            const parentSelector = this.buildSelector(element.parentElement);
            const tagName = element.tagName.toLowerCase();
            const index = this.getPositionIndex(element, tagName);

            return `${parentSelector} > ${tagName}:nth-of-type(${index})`;
        },

        /**
         * Get element's position index among siblings
         */
        getPositionIndex: function(element, filterTag) {
            const parent = element.parentElement;
            if (!parent) return 1;

            let index = 1;
            for (const child of parent.children) {
                if (child === element) return index;
                if (!filterTag || child.tagName.toLowerCase() === filterTag) {
                    index++;
                }
            }
            return index;
        },

        /**
         * Detect the type of page we're on
         */
        detectPageType: function() {
            if (document.body.classList.contains('home')) return 'home';
            if (document.body.classList.contains('single-post') ||
                document.body.classList.contains('single')) return 'article';
            if (document.body.classList.contains('archive') ||
                document.body.classList.contains('category')) return 'archive';
            if (document.body.classList.contains('page')) return 'page';
            return 'unknown';
        },

        /**
         * Highlight detected wrapper for visual feedback
         */
        highlightWrapper: function(element) {
            element.style.outline = '3px dashed #2271b1';
            element.style.outlineOffset = '2px';

            // Add label
            const label = document.createElement('div');
            label.style.cssText = `
                position: absolute;
                top: -25px;
                left: 0;
                background: #2271b1;
                color: white;
                padding: 2px 8px;
                font-size: 11px;
                font-family: monospace;
                border-radius: 3px;
                z-index: 999998;
                white-space: nowrap;
            `;
            label.textContent = 'ASR: ' + element.id;

            // Make wrapper relative if needed
            const computedStyle = window.getComputedStyle(element);
            if (computedStyle.position === 'static') {
                element.style.position = 'relative';
            }
            element.appendChild(label);
        },

        /**
         * Debounced send to avoid too many requests
         */
        debouncedSend: function() {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => this.sendData(), 2000);
        },

        /**
         * Send detected slots to WordPress admin
         */
        sendData: function() {
            if (this.detectedSlots.length === 0) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'asr_save_scan_data');
            formData.append('nonce', asrScanner.nonce);
            formData.append('slots', JSON.stringify(this.detectedSlots));

            fetch(asrScanner.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('[ASR Scanner] Data saved:', data.data.count, 'slots');
                }
            })
            .catch(error => {
                console.error('[ASR Scanner] Error saving data:', error);
            });
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ASR_Scanner.init());
    } else {
        ASR_Scanner.init();
    }

})();
