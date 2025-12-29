/**
 * Ad Space Reserve - Admin JavaScript
 */
(function($) {
    'use strict';

    const ASR_Admin = {
        /**
         * Initialize admin functionality
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Scan mode toggle
            $('#asr-scan-mode').on('change', this.toggleScanMode.bind(this));

            // Clear scan data
            $('#asr-clear-scan').on('click', this.clearScanData.bind(this));

            // Configure slot
            $('.asr-configure-slot').on('click', this.configureSlot.bind(this));

            // Remove configured slot
            $(document).on('click', '.asr-remove-slot', this.removeSlot.bind(this));

            // Update slot settings
            $(document).on('change', '.asr-height-input, .asr-injection-select, .asr-position-input',
                this.updateSlot.bind(this));

            // Generate code
            $('#asr-generate-code').on('click', this.generateCode.bind(this));

            // Save max infinite slots
            $('#asr-save-max-infinite').on('click', this.saveMaxInfiniteSlots.bind(this));
        },

        /**
         * Toggle scan mode
         */
        toggleScanMode: function(e) {
            const enabled = $(e.target).is(':checked');
            const $label = $(e.target).siblings('.asr-toggle-label');

            this.ajax('asr_toggle_scan_mode', { enabled: enabled ? 1 : 0 })
                .done(function(response) {
                    if (response.success) {
                        $label.text(enabled ?
                            asrAdmin.strings.scanEnabled || 'Scan Mode Active' :
                            asrAdmin.strings.scanDisabled || 'Scan Mode Disabled'
                        );

                        if (enabled) {
                            ASR_Admin.showNotice('info',
                                'Scan mode enabled. Visit your site pages to detect ad slots.');
                        }
                    }
                });
        },

        /**
         * Clear all scan data
         */
        clearScanData: function(e) {
            e.preventDefault();

            if (!confirm(asrAdmin.strings.confirmClear)) {
                return;
            }

            this.ajax('asr_clear_scan_data')
                .done(function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
        },

        /**
         * Configure a detected slot
         */
        configureSlot: function(e) {
            e.preventDefault();

            const $btn = $(e.target);
            const wrapperId = $btn.data('wrapper-id');
            const height = $btn.data('height');

            $btn.prop('disabled', true).text('Configuring...');

            this.ajax('asr_configure_slot', {
                wrapperId: wrapperId,
                minHeight: height,
                injectionLocation: 'after_paragraph',
                injectionPosition: 3
            })
            .done(function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    $btn.prop('disabled', false).text('Configure');
                    ASR_Admin.showNotice('error', response.data.message);
                }
            })
            .fail(function() {
                $btn.prop('disabled', false).text('Configure');
            });
        },

        /**
         * Remove a configured slot
         */
        removeSlot: function(e) {
            e.preventDefault();

            const $btn = $(e.target);
            const wrapperId = $btn.data('wrapper-id');

            $btn.prop('disabled', true);

            this.ajax('asr_unconfigure_slot', { wrapperId: wrapperId })
                .done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function() {
                    $btn.prop('disabled', false);
                });
        },

        /**
         * Update slot settings
         */
        updateSlot: function(e) {
            const $input = $(e.target);
            const wrapperId = $input.data('wrapper-id');
            let field, value;

            if ($input.hasClass('asr-height-input')) {
                field = 'minHeight';
                value = $input.val();
            } else if ($input.hasClass('asr-injection-select')) {
                field = 'injectionLocation';
                value = $input.val();
            } else if ($input.hasClass('asr-position-input')) {
                field = 'injectionPosition';
                value = $input.val();
            }

            this.ajax('asr_update_slot', {
                wrapperId: wrapperId,
                field: field,
                value: value
            });
        },

        /**
         * Generate code to child theme
         */
        generateCode: function(e) {
            e.preventDefault();

            if (!confirm(asrAdmin.strings.confirmGenerate)) {
                return;
            }

            const $btn = $(e.target);
            const originalText = $btn.text();

            $btn.prop('disabled', true)
                .html('<span class="asr-spinner"></span>' + asrAdmin.strings.generating);

            this.ajax('asr_generate_code')
                .done(function(response) {
                    if (response.success) {
                        ASR_Admin.showCodePreview(response.data);
                        ASR_Admin.showNotice('success',
                            'Code generated successfully! Files written to child theme.');
                    } else {
                        ASR_Admin.showNotice('error', response.data.message);
                    }
                })
                .fail(function() {
                    ASR_Admin.showNotice('error', asrAdmin.strings.error);
                })
                .always(function() {
                    $btn.prop('disabled', false).text(originalText);
                });
        },

        /**
         * Show code preview
         */
        showCodePreview: function(data) {
            const $preview = $('#asr-preview-card');

            if (data.php_preview) {
                $('#asr-php-preview').text(data.php_preview);
            }

            if (data.css_preview) {
                $('#asr-css-preview').text(data.css_preview);
            }

            // Build R89 targets list
            const settings = this.getConfiguredSlots();
            const $targets = $('#asr-r89-targets').empty();

            if (data.files) {
                // Parse configured slots from response or page
                $('.asr-slots-table tbody tr').each(function() {
                    const $row = $(this);
                    const cssClass = $row.find('code').text();
                    const slotName = $row.find('strong').text();
                    const device = $row.find('.asr-device-badge').text().trim();

                    if (cssClass) {
                        $targets.append(
                            '<li><strong>' + device + ' ' + slotName + ':</strong> ' +
                            '<code>' + cssClass + '</code> ' +
                            '<em>(inject inside)</em></li>'
                        );
                    }
                });
            }

            $preview.slideDown();

            // Scroll to preview
            $('html, body').animate({
                scrollTop: $preview.offset().top - 50
            }, 500);
        },

        /**
         * Get configured slots from the page
         */
        getConfiguredSlots: function() {
            const slots = [];
            $('.asr-slots-table tbody tr').each(function() {
                const $row = $(this);
                slots.push({
                    cssClass: $row.find('code').first().text(),
                    slotType: $row.find('strong').first().text()
                });
            });
            return slots;
        },

        /**
         * Show notice
         */
        showNotice: function(type, message) {
            const $notice = $('<div class="asr-notice asr-notice-' + type + '">' +
                '<p>' + message + '</p></div>');

            $('.asr-admin h1').after($notice);

            // Auto-remove after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);

            // Scroll to top
            $('html, body').animate({ scrollTop: 0 }, 300);
        },

        /**
         * Save max infinite slots setting
         */
        saveMaxInfiniteSlots: function(e) {
            e.preventDefault();

            const $btn = $(e.target);
            const $input = $('#asr-max-infinite-slots');
            const value = $input.val();
            const originalText = $btn.text();

            $btn.prop('disabled', true).text('Saving...');

            this.ajax('asr_save_max_infinite_slots', {
                max_infinite_slots: value
            })
            .done(function(response) {
                if (response.success) {
                    ASR_Admin.showNotice('success', 'Max infinite slots setting saved.');
                    $input.val(response.data.value);
                } else {
                    ASR_Admin.showNotice('error', response.data.message);
                }
            })
            .fail(function() {
                ASR_Admin.showNotice('error', asrAdmin.strings.error);
            })
            .always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        },

        /**
         * AJAX helper
         */
        ajax: function(action, data) {
            data = data || {};
            data.action = action;
            data.nonce = asrAdmin.nonce;

            return $.post(asrAdmin.ajaxUrl, data);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        ASR_Admin.init();
    });

})(jQuery);
