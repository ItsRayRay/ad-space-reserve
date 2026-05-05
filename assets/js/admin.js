/**
 * AdShimmer - Admin JavaScript
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
            // Configure slot
            $('.asr-configure-slot').on('click', this.configureSlot.bind(this));

            // Remove configured slot
            $(document).on('click', '.asr-remove-slot', this.removeSlot.bind(this));

            // Update slot settings
            $(document).on('change', '.asr-height-input, .asr-injection-select, .asr-position-input',
                this.updateSlot.bind(this));

            // Generate code
            $('#asr-generate-code').on('click', this.generateCode.bind(this));

            // Deploy code
            $('#asr-deploy-code').on('click', this.deployCode.bind(this));

            // Save max infinite slots
            $('#asr-save-max-infinite').on('click', this.saveMaxInfiniteSlots.bind(this));

            // Cleanup on deactivate checkbox
            $('#asr-cleanup-on-deactivate').on('change', this.saveCleanupSetting.bind(this));

            // Header code save
            $('#asr-save-header-code').on('click', this.saveHeaderCode.bind(this));

            // Attribution checkbox
            $('#asr-show-attribution').on('change', this.saveAttributionSetting.bind(this));

            // Header code blocks events
            this.bindHeaderBlockEvents();

            // Ads.txt events
            this.bindAdstxtEvents();

            // License events
            this.bindLicenseEvents();

            // OpenRouter API key events
            this.bindOpenRouterEvents();

            // Modal events
            this.bindModalEvents();

            // Import/Export events
            this.bindImportExportEvents();
        },

        /**
         * Bind license-related event handlers
         */
        bindLicenseEvents: function() {
            const self = this;

            // Activate license button
            $('#asr-activate-license').on('click', function() {
                var key = $('#asr-license-key').val().trim();
                if (!key) {
                    alert('Please enter a license key');
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true).text('Activating...');

                self.ajax('asr_activate_license', {
                    license_key: key
                })
                .done(function(response) {
                    if (response.success) {
                        self.updateLicenseStatus(response.data);
                    } else {
                        alert(response.data.message || 'Activation failed');
                    }
                })
                .fail(function() {
                    alert('Network error. Please try again.');
                })
                .always(function() {
                    $btn.prop('disabled', false).text('Activate');
                });
            });

            // Deactivate license button
            $('#asr-deactivate-license').on('click', function() {
                if (!confirm('Deactivate license from this site?')) return;

                var $btn = $(this);
                $btn.prop('disabled', true).text('Deactivating...');

                self.ajax('asr_deactivate_license')
                .done(function(response) {
                    if (response.success) {
                        self.updateLicenseStatus(response.data);
                    } else {
                        alert(response.data.message || 'Deactivation failed');
                    }
                })
                .always(function() {
                    $btn.prop('disabled', false).text('Deactivate');
                });
            });

            // Check license status on page load
            self.ajax('asr_check_license')
            .done(function(response) {
                if (response.success) {
                    self.updateLicenseStatus(response.data);
                }
            });
        },

        /**
         * Update license UI based on status data
         *
         * @param {object} data License data from API
         */
        updateLicenseStatus: function(data) {
            var $status = $('#asr-license-status');
            var $details = $('#asr-license-details');
            var $activateBtn = $('#asr-activate-license');
            var $deactivateBtn = $('#asr-deactivate-license');

            $status.removeClass('status-active status-inactive status-expired');

            if (data.status === 'active') {
                $status.addClass('status-active').html('<strong>✓ License Active</strong>');
                $details.show();
                $('#asr-license-status-text').text('Active');
                $('#asr-license-sites').text(data.activation_usage + ' / ' + (data.activation_limit || 'Unlimited'));
                $('#asr-license-expires').text(data.expires_at ? data.expires_at : 'Never (Lifetime)');
                $activateBtn.hide();
                $deactivateBtn.show();
                $('#asr-license-key').prop('readonly', true);
            } else if (data.status === 'expired') {
                $status.addClass('status-expired').html('<strong>✗ License Expired</strong>');
                $details.hide();
                $activateBtn.show();
                $deactivateBtn.hide();
                $('#asr-license-key').prop('readonly', false);
            } else {
                $status.addClass('status-inactive').html('<strong>⚡ Enter license key to activate</strong>');
                $details.hide();
                $activateBtn.show();
                $deactivateBtn.hide();
                $('#asr-license-key').prop('readonly', false);
            }
        },

        /**
         * Bind OpenRouter API key event handlers
         */
        bindOpenRouterEvents: function() {
            const self = this;

            // Toggle password visibility
            $('#asr-toggle-key-visibility').on('click', function() {
                const $input = $('#asr-openrouter-key');
                const $icon = $(this).find('.dashicons');

                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    $input.attr('type', 'password');
                    $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });

            // Save API key button
            $('#asr-save-openrouter-key').on('click', function() {
                self.saveOpenRouterKey();
            });

            // Remove API key button
            $('#asr-remove-openrouter-key').on('click', function() {
                self.removeOpenRouterKey();
            });

            // Allow Enter key to submit
            $('#asr-openrouter-key').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.saveOpenRouterKey();
                }
            });
        },

        /**
         * Save and validate OpenRouter API key
         */
        saveOpenRouterKey: function() {
            const self = this;
            const $btn = $('#asr-save-openrouter-key');
            const $input = $('#asr-openrouter-key');
            const $status = $('#asr-openrouter-status');
            const $aiStatus = $('#asr-ai-status');
            const originalText = $btn.text();
            const apiKey = $input.val().trim();

            // Clear previous status
            $status.text('').removeClass('error');

            // Basic client-side validation
            if (apiKey && apiKey.indexOf('sk-or-') !== 0) {
                $status.addClass('error').text(asrAdmin.strings.keyInvalidFormat || 'Invalid API key format.');
                return;
            }

            $btn.prop('disabled', true).text(asrAdmin.strings.savingKey || 'Validating...');

            this.ajax('asr_save_openrouter_key', {
                api_key: apiKey
            })
            .done(function(response) {
                if (response.success) {
                    $status.text(response.data.message);

                    // Update status indicator
                    self.updateAIStatus(response.data.status, response.data.masked_key);

                    // Clear input field for security (key is now stored)
                    if (response.data.status === 'valid') {
                        $input.val('');
                        $input.attr('placeholder', response.data.masked_key);
                    }
                } else {
                    $status.addClass('error').text(response.data.message);
                    // Mark as invalid
                    self.updateAIStatus('invalid');
                }
            })
            .fail(function() {
                $status.addClass('error').text(asrAdmin.strings.error || 'Error occurred');
            })
            .always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Remove OpenRouter API key
         */
        removeOpenRouterKey: function() {
            const self = this;
            const $btn = $('#asr-remove-openrouter-key');
            const $status = $('#asr-openrouter-status');
            const originalText = $btn.text();

            // Confirm removal
            if (!confirm(asrAdmin.strings.confirmRemoveKey || 'Are you sure you want to remove the API key?')) {
                return;
            }

            $btn.prop('disabled', true).text(asrAdmin.strings.removing || 'Removing...');
            $status.text('').removeClass('error');

            this.ajax('asr_save_openrouter_key', {
                api_key: '' // Empty key to clear
            })
            .done(function(response) {
                if (response.success) {
                    $status.text(response.data.message);
                    self.updateAIStatus('not_configured');
                } else {
                    $status.addClass('error').text(response.data.message);
                }
            })
            .fail(function() {
                $status.addClass('error').text(asrAdmin.strings.error || 'Error occurred');
            })
            .always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Update the AI status indicator
         *
         * @param {string} status 'not_configured', 'valid', or 'invalid'
         * @param {string} maskedKey Optional masked key to display
         */
        updateAIStatus: function(status, maskedKey) {
            const $aiStatus = $('#asr-ai-status');
            const $statusText = $aiStatus.find('.asr-ai-status-text');

            // Remove all status classes
            $aiStatus.removeClass('status-not-configured status-valid status-invalid');

            // Add new status class and update text
            switch (status) {
                case 'valid':
                    $aiStatus.addClass('status-valid');
                    $statusText.text('Valid');
                    break;
                case 'invalid':
                    $aiStatus.addClass('status-invalid');
                    $statusText.text('Invalid');
                    break;
                case 'not_configured':
                default:
                    $aiStatus.addClass('status-not-configured');
                    $statusText.text('Not configured');
                    break;
            }

            // Update the current key display if provided
            if (maskedKey) {
                let $keyDisplay = $('.asr-key-configured');
                if ($keyDisplay.length === 0) {
                    // Create the element if it doesn't exist
                    $('#asr-openrouter-key').closest('td').find('.description').after(
                        '<p class="asr-key-configured">Current key: <code>' + maskedKey + '</code></p>'
                    );
                } else {
                    $keyDisplay.find('code').text(maskedKey);
                }
            }

            if (status === 'not_configured') {
                // Remove key display when cleared
                $('.asr-key-configured').remove();
                // Reset placeholder to default
                $('#asr-openrouter-key').attr('placeholder', 'sk-or-v1-...');
                // Hide the Remove button
                $('#asr-remove-openrouter-key').hide();
            } else if (status === 'valid') {
                // Show the Remove button when key is configured
                $('#asr-remove-openrouter-key').show();
            }
        },

        /**
         * Bind ads.txt event handlers
         */
        bindAdstxtEvents: function() {
            const self = this;

            // Mode radio button change handler - toggle content/redirect sections
            $('input[name="adstxt_mode"]').on('change', function() {
                const mode = $(this).val();

                // Hide all sections first
                $('#asr-adstxt-content-section').hide();
                $('#asr-adstxt-redirect-section').hide();

                // Show appropriate section based on mode
                if (mode === 'content') {
                    $('#asr-adstxt-content-section').show();
                } else if (mode === 'redirect') {
                    $('#asr-adstxt-redirect-section').show();
                }
            });

            // Save ads.txt button handler
            $('#asr-save-adstxt').on('click', function() {
                self.saveAdstxt();
            });
        },

        /**
         * Save ads.txt settings via AJAX
         */
        saveAdstxt: function() {
            const self = this;
            const $btn = $('#asr-save-adstxt');
            const $status = $('#asr-adstxt-status');
            const originalText = $btn.text();

            // Get form values
            const mode = $('input[name="adstxt_mode"]:checked').val();
            const content = $('#asr-adstxt-content').val();
            const redirectUrl = $('#asr-adstxt-redirect-url').val();

            // Basic URL validation for redirect mode
            if (mode === 'redirect' && redirectUrl) {
                try {
                    new URL(redirectUrl);
                } catch (e) {
                    $status.addClass('error').text(asrAdmin.strings.adstxtInvalidUrl || 'Please enter a valid URL for redirect mode.');
                    return;
                }
            }

            $btn.prop('disabled', true).text(asrAdmin.strings.savingAdstxt || 'Saving...');
            $status.text('').removeClass('error');

            this.ajax('asr_save_adstxt', {
                adstxt_mode: mode,
                adstxt_content: content,
                adstxt_redirect_url: redirectUrl
            })
            .done(function(response) {
                if (response.success) {
                    $status.text(asrAdmin.strings.adstxtSaved || 'Ads.txt settings saved.');
                } else {
                    $status.addClass('error').text(response.data.message);
                }
            })
            .fail(function() {
                $status.addClass('error').text(asrAdmin.strings.error || 'Error occurred');
            })
            .always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Bind modal event handlers
         */
        bindModalEvents: function() {
            const self = this;

            // Open modal for adding new slot (supports both ID and class)
            $('#asr-add-slot, .asr-add-slot').on('click', function(e) {
                e.preventDefault();
                self.openSlotModal('add');
            });

            // Open modal for editing existing slot (delegated for dynamic rows)
            $('#asr-slots-tbody').on('click', '.asr-btn-edit', function(e) {
                e.preventDefault();
                const slotData = $(this).data('slot');
                self.openSlotModal('edit', slotData);
            });

            // Delete button (delegated for dynamic rows)
            $('#asr-slots-tbody').on('click', '.asr-btn-delete', function(e) {
                e.preventDefault();
                const slotId = $(this).data('slot-id');
                const slotName = $(this).data('slot-name');
                self.deleteSlot(slotId, slotName);
            });

            // Close modal on close button click
            $('.asr-modal-close').on('click', function() {
                self.closeSlotModal();
            });

            // Close modal on Cancel button click
            $('#asr-modal-cancel').on('click', function() {
                self.closeSlotModal();
            });

            // Close modal on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#asr-slot-modal-overlay').hasClass('is-visible')) {
                    self.closeSlotModal();
                }
            });

            // Type dropdown change handler
            $('#asr-slot-type').on('change', function() {
                self.handleTypeChange($(this).val());
            });

            // Sticky checkbox change handler - toggle offset row visibility
            $('#asr-slot-sticky').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.asr-sticky-offset-row').addClass('is-visible');
                } else {
                    $('.asr-sticky-offset-row').removeClass('is-visible');
                }
            });

            // Fallback checkbox change handler - toggle URL, timeout, and link row visibility
            $('#asr-slot-fallback').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.asr-fallback-url-row').addClass('is-visible');
                    $('.asr-fallback-timeout-row').addClass('is-visible');
                    $('.asr-fallback-link-row').addClass('is-visible');
                } else {
                    $('.asr-fallback-url-row').removeClass('is-visible');
                    $('.asr-fallback-timeout-row').removeClass('is-visible');
                    $('.asr-fallback-link-row').removeClass('is-visible');
                }
            });

            // Show advanced checkbox change handler - toggle advanced options rows visibility
            $('#asr-show-advanced').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.asr-selector-mode-row').addClass('is-visible');
                    $('.asr-custom-css-row').addClass('is-visible');
                } else {
                    $('.asr-selector-mode-row').removeClass('is-visible');
                    $('.asr-custom-css-row').removeClass('is-visible');
                }
            });

            // Media library button handler for fallback image selection
            $('#asr-select-fallback-image').on('click', function(e) {
                e.preventDefault();

                // Create media frame if not exists
                var mediaFrame = wp.media({
                    title: 'Select Fallback Image',
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' }
                });

                // On select, set URL input value
                mediaFrame.on('select', function() {
                    var attachment = mediaFrame.state().get('selection').first().toJSON();
                    $('#asr-slot-fallback-url').val(attachment.url);
                });

                mediaFrame.open();
            });

            // Save button click
            $('#asr-modal-save').on('click', function() {
                self.handleSlotSave();
            });

            // Form submit (prevent default, trigger save)
            $('#asr-slot-form').on('submit', function(e) {
                e.preventDefault();
                self.handleSlotSave();
            });
        },

        /**
         * Open the slot modal
         *
         * @param {string} mode 'add' or 'edit'
         * @param {object} slotData Optional slot data for edit mode
         */
        openSlotModal: function(mode, slotData) {
            const $overlay = $('#asr-slot-modal-overlay');
            const $modal = $overlay.find('.asr-modal');
            const $title = $('#asr-modal-title');

            // Set mode
            $modal.attr('data-mode', mode);

            // Update title
            if (mode === 'edit') {
                $title.text(asrAdmin.strings.editSlot || 'Edit Slot');
            } else {
                $title.text(asrAdmin.strings.addSlot || 'Add New Slot');
            }

            // Reset form
            this.resetSlotForm();

            // Populate form if editing
            if (mode === 'edit' && slotData) {
                this.populateSlotForm(slotData);
            }

            // Show modal
            $overlay.addClass('is-visible');

            // Focus first input
            setTimeout(function() {
                $('#asr-slot-name').focus();
            }, 100);
        },

        /**
         * Close the slot modal
         */
        closeSlotModal: function() {
            const $overlay = $('#asr-slot-modal-overlay');
            $overlay.removeClass('is-visible');
            this.resetSlotForm();
        },

        /**
         * Reset the slot form to defaults
         */
        resetSlotForm: function() {
            const $form = $('#asr-slot-form');

            // Reset all fields
            $form[0].reset();

            // Clear hidden slot ID
            $('#asr-slot-id').val('');

            // Reset to default values
            $('#asr-slot-type').val('custom');
            $('#asr-slot-device').val('desktop');
            $('#asr-slot-placement').val('after');
            $('#asr-slot-position').val(0);
            $('#asr-slot-target-paths').val('');
            $('#asr-slot-min-height').val(250);
            $('#asr-slot-margin-top').val(15);
            $('#asr-slot-margin-bottom').val(15);

            // Reset sticky fields
            $('#asr-slot-sticky').prop('checked', false);
            $('#asr-slot-sticky-offset').val(0);
            $('.asr-sticky-offset-row').removeClass('is-visible');

            // Reset fallback fields
            $('#asr-slot-fallback').prop('checked', false);
            $('#asr-slot-fallback-url').val('');
            $('#asr-slot-fallback-timeout').val(2000);
            $('#asr-slot-fallback-link').val('');
            $('.asr-fallback-url-row').removeClass('is-visible');
            $('.asr-fallback-timeout-row').removeClass('is-visible');
            $('.asr-fallback-link-row').removeClass('is-visible');

            // Reset advanced fields
            $('#asr-show-advanced').prop('checked', false);
            $('#asr-slot-selector-mode').val('first');
            $('#asr-slot-custom-css').val('background-color: #e0e0e0;');
            $('.asr-selector-mode-row').removeClass('is-visible');
            $('.asr-custom-css-row').removeClass('is-visible');

            // Clear all error states
            $form.find('.asr-form-row').removeClass('has-error');
            $form.find('.error-message').text('').hide();
        },

        /**
         * Populate the slot form with existing data
         *
         * @param {object} slotData Slot data object
         */
        populateSlotForm: function(slotData) {
            if (!slotData) return;

            // Set form values
            if (slotData.id) $('#asr-slot-id').val(slotData.id);
            if (slotData.name) $('#asr-slot-name').val(slotData.name);
            // Set type without triggering change event (which would override device)
            if (slotData.type) {
                const $typeSelect = $('#asr-slot-type');
                $typeSelect.off('change').val(slotData.type);
                // Re-bind the change handler after setting value
                const self = this;
                $typeSelect.on('change', function() {
                    self.handleTypeChange($(this).val());
                });
            }
            if (slotData.device) $('#asr-slot-device').val(slotData.device);
            if (slotData.selector) $('#asr-slot-selector').val(slotData.selector);
            if (slotData.placement) $('#asr-slot-placement').val(slotData.placement);
            if (slotData.position !== undefined) $('#asr-slot-position').val(slotData.position);
            if (slotData.target_paths !== undefined) $('#asr-slot-target-paths').val(slotData.target_paths);
            if (slotData.min_height !== undefined) $('#asr-slot-min-height').val(slotData.min_height);
            if (slotData.margin_top !== undefined) $('#asr-slot-margin-top').val(slotData.margin_top);
            if (slotData.margin_bottom !== undefined) $('#asr-slot-margin-bottom').val(slotData.margin_bottom);

            // Set sticky fields
            const isSticky = slotData.is_sticky === true || slotData.is_sticky === 1 || slotData.is_sticky === '1';
            $('#asr-slot-sticky').prop('checked', isSticky);
            if (slotData.sticky_offset !== undefined) $('#asr-slot-sticky-offset').val(slotData.sticky_offset);

            // Toggle offset row visibility based on sticky checkbox state
            if (isSticky) {
                $('.asr-sticky-offset-row').addClass('is-visible');
            } else {
                $('.asr-sticky-offset-row').removeClass('is-visible');
            }

            // Set fallback fields
            const hasFallback = slotData.has_fallback === true || slotData.has_fallback === 1 || slotData.has_fallback === '1';
            $('#asr-slot-fallback').prop('checked', hasFallback);
            if (slotData.fallback_image_url !== undefined) $('#asr-slot-fallback-url').val(slotData.fallback_image_url);
            if (slotData.fallback_timeout !== undefined) $('#asr-slot-fallback-timeout').val(slotData.fallback_timeout);
            if (slotData.fallback_link_url !== undefined) $('#asr-slot-fallback-link').val(slotData.fallback_link_url);

            // Toggle fallback rows visibility based on checkbox state
            if (hasFallback) {
                $('.asr-fallback-url-row').addClass('is-visible');
                $('.asr-fallback-timeout-row').addClass('is-visible');
                $('.asr-fallback-link-row').addClass('is-visible');
            } else {
                $('.asr-fallback-url-row').removeClass('is-visible');
                $('.asr-fallback-timeout-row').removeClass('is-visible');
                $('.asr-fallback-link-row').removeClass('is-visible');
            }

            // Set advanced fields
            const hasAdvancedOptions = slotData.custom_css || (slotData.selector_mode && slotData.selector_mode !== 'first');

            if (slotData.selector_mode) {
                $('#asr-slot-selector-mode').val(slotData.selector_mode);
            }

            if (slotData.custom_css) {
                $('#asr-slot-custom-css').val(slotData.custom_css);
            }

            // Auto-show advanced section if any advanced options are set
            if (hasAdvancedOptions) {
                $('#asr-show-advanced').prop('checked', true);
                $('.asr-selector-mode-row').addClass('is-visible');
                $('.asr-custom-css-row').addClass('is-visible');
            }
        },

        /**
         * Get form data as object
         *
         * @return {object} Form data
         */
        getSlotFormData: function() {
            return {
                id: $('#asr-slot-id').val(),
                name: $('#asr-slot-name').val().trim(),
                type: $('#asr-slot-type').val(),
                device: $('#asr-slot-device').val(),
                selector: $('#asr-slot-selector').val().trim(),
                placement: $('#asr-slot-placement').val(),
                position: parseInt($('#asr-slot-position').val(), 10) || 0,
                target_paths: $('#asr-slot-target-paths').val().trim(),
                min_height: parseInt($('#asr-slot-min-height').val(), 10) || 0,
                margin_top: parseInt($('#asr-slot-margin-top').val(), 10) || 0,
                margin_bottom: parseInt($('#asr-slot-margin-bottom').val(), 10) || 0,
                is_sticky: $('#asr-slot-sticky').is(':checked'),
                sticky_offset: parseInt($('#asr-slot-sticky-offset').val(), 10) || 0,
                has_fallback: $('#asr-slot-fallback').is(':checked'),
                fallback_image_url: $('#asr-slot-fallback-url').val().trim(),
                fallback_timeout: parseInt($('#asr-slot-fallback-timeout').val(), 10) || 2000,
                fallback_link_url: $('#asr-slot-fallback-link').val().trim(),
                custom_css: $('#asr-slot-custom-css').val().trim(),
                selector_mode: $('#asr-slot-selector-mode').val()
            };
        },

        /**
         * Validate the slot form
         *
         * @return {object} Errors object (empty if valid)
         */
        validateSlotForm: function() {
            const data = this.getSlotFormData();
            const errors = {};

            // Name validation
            if (!data.name) {
                errors.name = asrAdmin.strings.nameRequired || 'Slot name is required.';
            }

            // Selector validation
            if (!data.selector) {
                errors.selector = asrAdmin.strings.selectorRequired || 'Target selector is required.';
            } else if (!this.isValidSelector(data.selector)) {
                errors.selector = asrAdmin.strings.selectorInvalid || 'Selector must start with . or # or be a valid tag name.';
            }

            // Min height validation
            if (data.min_height <= 0) {
                errors.min_height = asrAdmin.strings.heightRequired || 'Minimum height must be a positive number.';
            }

            if (data.position < 0 || data.position > 1000) {
                errors.position = 'Paragraph position must be between 0 and 1000.';
            }

            return errors;
        },

        /**
         * Check if selector is valid
         *
         * @param {string} selector CSS selector
         * @return {boolean}
         */
        isValidSelector: function(selector) {
            // Must start with ., #, or alphabetic character (tag name)
            const firstChar = selector.charAt(0);
            if (firstChar === '.' || firstChar === '#') {
                return selector.length > 1;
            }

            // Check if starts with alphabetic character (tag name)
            return /^[a-zA-Z]/.test(selector);
        },

        /**
         * Show validation errors on form
         *
         * @param {object} errors Errors object
         */
        showFormErrors: function(errors) {
            const $form = $('#asr-slot-form');

            // Clear previous errors
            $form.find('.asr-form-row').removeClass('has-error');
            $form.find('.error-message').text('').hide();

            // Show new errors
            for (const field in errors) {
                const $row = $form.find('#asr-slot-' + field.replace('_', '-')).closest('.asr-form-row');
                $row.addClass('has-error');
                $row.find('.error-message').text(errors[field]).show();
            }
        },

        /**
         * Handle type dropdown change
         *
         * @param {string} typeValue Selected type value
         */
        handleTypeChange: function(typeValue) {
            const $deviceSelect = $('#asr-slot-device');
            const $heightInput = $('#asr-slot-min-height');

            if (typeValue === 'custom') {
                // Custom type - enable all device options, use default height
                $deviceSelect.val('both');
                $heightInput.val($heightInput.data('default') || 250);
                return;
            }

            // Parse device from type (e.g., "desktop-billboard-btf" -> "desktop")
            const parts = typeValue.split('-');
            const device = parts[0];
            const slotKey = parts.slice(1).join('-');

            // Auto-set device
            if (device === 'desktop' || device === 'mobile') {
                $deviceSelect.val(device);
            }

            // Get slot data and set height
            if (asrAdmin.slotDefaults) {
                const slots = device === 'desktop' ? asrAdmin.slotDefaults.desktop : asrAdmin.slotDefaults.mobile;
                if (slots && slots[slotKey]) {
                    $heightInput.val(slots[slotKey].height);
                }
            }

            // Also check for data-height attribute on selected option
            const $selectedOption = $('#asr-slot-type option:selected');
            const dataHeight = $selectedOption.data('height');
            if (dataHeight) {
                $heightInput.val(dataHeight);
            }
        },

        /**
         * Handle slot save button click
         */
        handleSlotSave: function() {
            const self = this;
            const errors = this.validateSlotForm();

            if (Object.keys(errors).length > 0) {
                this.showFormErrors(errors);
                return;
            }

            const formData = this.getSlotFormData();
            const $saveBtn = $('#asr-modal-save');
            const originalText = $saveBtn.text();

            // Disable button, show loading
            $saveBtn.prop('disabled', true).text(asrAdmin.strings.generating || 'Saving...');

            this.ajax('asr_save_slot', {
                slot_id: formData.id,
                name: formData.name,
                type: formData.type,
                device: formData.device,
                selector: formData.selector,
                placement: formData.placement,
                position: formData.position,
                target_paths: formData.target_paths,
                min_height: formData.min_height,
                margin_top: formData.margin_top,
                margin_bottom: formData.margin_bottom,
                is_sticky: formData.is_sticky ? 1 : 0,
                sticky_offset: formData.sticky_offset,
                has_fallback: formData.has_fallback ? 1 : 0,
                fallback_image_url: formData.fallback_image_url,
                fallback_timeout: formData.fallback_timeout,
                fallback_link_url: formData.fallback_link_url,
                custom_css: formData.custom_css,
                selector_mode: formData.selector_mode
            })
            .done(function(response) {
                if (response.success) {
                    self.closeSlotModal();
                    self.refreshSlotsTable();
                    self.showNotice('success', response.data.message);
                } else {
                    // Show server-side errors
                    if (response.data.errors && Array.isArray(response.data.errors)) {
                        // Convert array of errors to object format for showFormErrors
                        const errorObj = {};
                        response.data.errors.forEach(function(err) {
                            // Try to extract field from error message
                            if (err.toLowerCase().indexOf('name') !== -1) {
                                errorObj.name = err;
                            } else if (err.toLowerCase().indexOf('selector') !== -1) {
                                errorObj.selector = err;
                            } else if (err.toLowerCase().indexOf('height') !== -1) {
                                errorObj.min_height = err;
                            }
                        });
                        self.showFormErrors(errorObj);
                    }
                    self.showNotice('error', response.data.message);
                }
            })
            .fail(function() {
                self.showNotice('error', asrAdmin.strings.error || 'Error occurred');
            })
            .always(function() {
                $saveBtn.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Delete a slot via AJAX
         *
         * @param {string} slotId The slot ID to delete
         * @param {string} slotName The slot name for confirmation
         */
        deleteSlot: function(slotId, slotName) {
            const self = this;
            const confirmMsg = (asrAdmin.strings.confirmDelete || 'Are you sure you want to delete "%s"?').replace('%s', slotName);

            if (!confirm(confirmMsg)) {
                return;
            }

            this.ajax('asr_delete_slot', {
                slot_id: slotId
            })
            .done(function(response) {
                if (response.success) {
                    self.refreshSlotsTable();
                    self.showNotice('success', response.data.message);
                } else {
                    self.showNotice('error', response.data.message);
                }
            })
            .fail(function() {
                self.showNotice('error', asrAdmin.strings.error || 'Error occurred');
            });
        },

        /**
         * Refresh the slots table after CRUD operations
         */
        refreshSlotsTable: function() {
            // Simple approach: reload the page to get fresh table HTML
            // This ensures all server-side rendering is correct
            location.reload();
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
         * Show generate status message
         *
         * @param {string} type 'success' or 'error'
         * @param {string} message Message to display
         */
        showGenerateStatus: function(type, message) {
            const $status = $('#asr-generate-status');
            $status.removeClass('success error').addClass(type).text(message).show();
        },

        /**
         * Generate code from configured slots
         */
        generateCode: function(e) {
            e.preventDefault();

            const self = this;
            const $btn = $('#asr-generate-code');
            const $deployBtn = $('#asr-deploy-code');
            const originalText = $btn.text();

            $btn.prop('disabled', true).text(asrAdmin.strings.generating || 'Generating...');

            // Reset deploy button when generating new code
            $deployBtn.prop('disabled', true).text(asrAdmin.strings.deployToTheme || 'Deploy to Theme');
            $('#asr-deploy-status').hide();

            // Hide previous status/preview
            $('#asr-generate-status').hide();
            $('#asr-code-preview').hide();

            this.ajax('asr_generate_code')
                .done(function(response) {
                    if (response.success) {
                        const data = response.data;
                        self.showGenerateStatus('success',
                            'Code generated successfully! CSS: ' + data.css_lines + ' lines, ' + data.slots_count + ' slot(s)'
                        );
                        // Show CSS preview
                        if (data.css_preview) {
                            $('#asr-code-preview').text(data.css_preview + '\n...').show();
                        }
                        // Enable deploy button after successful generation
                        $deployBtn.prop('disabled', false);
                    } else {
                        const errors = response.data.errors ? response.data.errors.join('\n') : 'Unknown error';
                        self.showGenerateStatus('error', errors);
                    }
                })
                .fail(function() {
                    self.showGenerateStatus('error', asrAdmin.strings.error || 'Error occurred');
                })
                .always(function() {
                    $btn.prop('disabled', false).text(originalText);
                });
        },

        /**
         * Deploy generated code to child theme
         */
        deployCode: function(e) {
            e.preventDefault();

            // Confirmation dialog
            if (!confirm(asrAdmin.strings.confirmDeploy || 'This will write files to your child theme. Any existing files will be backed up. Continue?')) {
                return;
            }

            const self = this;
            const $btn = $('#asr-deploy-code');
            const $status = $('#asr-deploy-status');
            const originalText = $btn.text();

            $btn.prop('disabled', true).text(asrAdmin.strings.deploying || 'Deploying...');
            $status.hide();

            this.ajax('asr_deploy_code')
                .done(function(response) {
                    if (response.success) {
                        $status
                            .removeClass('error')
                            .addClass('success')
                            .empty()
                            .append($('<strong>').text(asrAdmin.strings.deployed || 'Deployed!'))
                            .append($('<span>').text(' ' + response.data.message));

                        // Show deployed files if available
                        if (response.data.files && response.data.files.length) {
                            var $fileList = $('<div class="file-list">').append($('<strong>').text('Files written:'));
                            var $ul = $('<ul>');
                            response.data.files.forEach(function(file) {
                                $ul.append($('<li>').text(file));
                            });
                            $fileList.append($ul);
                            $status.append($fileList);
                        }

                        $status.show();

                        // Keep button disabled after deploy (need to regenerate)
                        $btn.text(asrAdmin.strings.deployed || 'Deployed!');
                    } else {
                        $status
                            .removeClass('success')
                            .addClass('error')
                            .empty()
                            .append($('<strong>').text((asrAdmin.strings.error || 'Error') + ':'))
                            .append($('<span>').text(' ' + response.data.message))
                            .show();
                        $btn.prop('disabled', false).text(originalText);
                    }
                })
                .fail(function() {
                    $status
                        .removeClass('success')
                        .addClass('error')
                        .empty()
                        .append($('<strong>').text((asrAdmin.strings.error || 'Error') + ':'))
                        .append(' ' + (asrAdmin.strings.error || 'Error occurred'))
                        .show();
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

            // Build targets list
            const settings = this.getConfiguredSlots();
            const $targets = $('#asr-targets').empty();

            if (data.files) {
                // Parse configured slots from response or page
                $('.asr-slots-table tbody tr').each(function() {
                    const $row = $(this);
                    const cssClass = $row.find('code').text();
                    const slotName = $row.find('strong').text();
                    const device = $row.find('.asr-device-badge').text().trim();

                    if (cssClass) {
                        $targets.append(
                            $('<li>')
                                .append($('<strong>').text(device + ' ' + slotName + ':'))
                                .append(' ')
                                .append($('<code>').text(cssClass))
                                .append(' ')
                                .append($('<em>').text('(inject inside)'))
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
            const $notice = $('<div>').addClass('asr-notice asr-notice-' + type)
                .append($('<p>').text(message));

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
         * Save cleanup on deactivate setting
         */
        saveCleanupSetting: function(e) {
            const self = this;
            const $checkbox = $(e.target);
            const enabled = $checkbox.is(':checked');

            this.ajax('asr_save_cleanup_setting', {
                cleanup_on_deactivate: enabled ? 1 : 0
            })
            .done(function(response) {
                if (response.success) {
                    self.showNotice('success', 'Cleanup setting saved.');
                } else {
                    // Revert checkbox on failure
                    $checkbox.prop('checked', !enabled);
                    self.showNotice('error', response.data.message);
                }
            })
            .fail(function() {
                // Revert checkbox on failure
                $checkbox.prop('checked', !enabled);
                self.showNotice('error', asrAdmin.strings.error);
            });
        },

        /**
         * Save attribution setting
         */
        saveAttributionSetting: function(e) {
            const self = this;
            const $checkbox = $(e.target);
            const enabled = $checkbox.is(':checked');

            this.ajax('asr_save_attribution', {
                show_attribution: enabled ? 1 : 0
            })
            .done(function(response) {
                if (response.success) {
                    self.showNotice('success', 'Attribution setting saved.');
                } else {
                    // Revert checkbox on failure
                    $checkbox.prop('checked', !enabled);
                    self.showNotice('error', response.data.message);
                }
            })
            .fail(function() {
                // Revert checkbox on failure
                $checkbox.prop('checked', !enabled);
                self.showNotice('error', asrAdmin.strings.error);
            });
        },

        /**
         * Save header code
         */
        saveHeaderCode: function(e) {
            e.preventDefault();

            const self = this;
            const $btn = $('#asr-save-header-code');
            const $textarea = $('#asr-header-code');
            const $status = $('#asr-header-code-status');
            const originalText = $btn.text();
            const code = $textarea.val();

            $btn.prop('disabled', true).text(asrAdmin.strings.savingHeader || 'Saving...');
            $status.text('').removeClass('error');

            this.ajax('asr_save_header_code', {
                header_code: code
            })
            .done(function(response) {
                if (response.success) {
                    $status.text(asrAdmin.strings.headerSaved || 'Saved!');
                } else {
                    $status.addClass('error').text(response.data.message);
                }
            })
            .fail(function() {
                $status.addClass('error').text(asrAdmin.strings.error);
            })
            .always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Bind header code blocks event handlers
         */
        bindHeaderBlockEvents: function() {
            const self = this;

            // Add new block
            $('#asr-add-header-block').on('click', function() {
                self.addHeaderBlock();
            });

            // Remove block (delegated)
            $('#asr-header-blocks').on('click', '.asr-remove-block', function() {
                const $block = $(this).closest('.asr-header-block');
                if (confirm(asrAdmin.strings.confirmRemoveBlock || 'Remove this code block?')) {
                    $block.remove();
                }
            });

            // Save all blocks
            $('#asr-save-header-blocks').on('click', function() {
                self.saveHeaderBlocks();
            });
        },

        /**
         * Generate unique block ID
         */
        generateBlockId: function() {
            return 'block-' + Date.now() + '-' + Math.floor(Math.random() * 10000);
        },

        /**
         * Add a new header code block
         */
        addHeaderBlock: function() {
            const blockId = this.generateBlockId();
            const blockHtml = '<div class="asr-header-block" data-block-id="' + blockId + '">' +
                '<div class="asr-header-block-header">' +
                    '<input type="checkbox" class="asr-block-enabled" checked>' +
                    '<input type="text" class="asr-block-label" value="" placeholder="' +
                        (asrAdmin.strings.blockLabel || 'Block label (e.g., Google AdSense)') + '">' +
                    '<button type="button" class="asr-remove-block button-link-delete">' +
                        (asrAdmin.strings.removeBlock || 'Remove') +
                    '</button>' +
                '</div>' +
                '<textarea class="asr-block-code asr-code-textarea" rows="4" placeholder="<!-- Paste code here -->"></textarea>' +
            '</div>';

            $('#asr-header-blocks').append(blockHtml);

            // Focus the new label input
            $('#asr-header-blocks .asr-header-block:last .asr-block-label').focus();
        },

        /**
         * Save all header code blocks via AJAX
         */
        saveHeaderBlocks: function() {
            const self = this;
            const $btn = $('#asr-save-header-blocks');
            const $status = $('#asr-header-blocks-status');
            const originalText = $btn.text();
            const blocks = [];

            // Collect all blocks
            $('#asr-header-blocks .asr-header-block').each(function() {
                const $block = $(this);
                blocks.push({
                    id: $block.data('block-id'),
                    label: $block.find('.asr-block-label').val().trim(),
                    code: $block.find('.asr-block-code').val(),
                    enabled: $block.find('.asr-block-enabled').is(':checked')
                });
            });

            $btn.prop('disabled', true).text(asrAdmin.strings.savingHeaderBlocks || 'Saving...');
            $status.text('').removeClass('error');

            this.ajax('asr_save_header_blocks', {
                blocks: JSON.stringify(blocks)
            })
            .done(function(response) {
                if (response.success) {
                    $status.text(asrAdmin.strings.headerBlocksSaved || 'Header code blocks saved.');
                } else {
                    $status.addClass('error').text(response.data.message);
                }
            })
            .fail(function() {
                $status.addClass('error').text(asrAdmin.strings.error);
            })
            .always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Bind import/export slot event handlers
         */
        bindImportExportEvents: function() {
            const self = this;

            // Export slots - create hidden form and submit for file download
            $('#asr-export-slots').on('click', function() {
                const $btn = $(this);
                const originalText = $btn.text();

                // Check if there are any slots to export
                if ($('.asr-slots-table').length === 0) {
                    self.showNotice('error', asrAdmin.strings.noSlotsToExport || 'No slots to export.');
                    return;
                }

                $btn.prop('disabled', true).text(asrAdmin.strings.exporting || 'Exporting...');

                // Create a hidden form to trigger the file download via POST
                const $form = $('<form>', {
                    method: 'POST',
                    action: asrAdmin.ajaxUrl,
                    css: { display: 'none' }
                });

                $form.append($('<input>', { type: 'hidden', name: 'action', value: 'asr_export_slots' }));
                $form.append($('<input>', {
                    type: 'hidden',
                    name: 'nonce',
                    value: (asrAdmin.nonces && asrAdmin.nonces.asr_export_slots) || asrAdmin.nonce
                }));

                $('body').append($form);
                $form[0].submit();
                $form.remove();

                // Re-enable button after a short delay
                setTimeout(function() {
                    $btn.prop('disabled', false).text(originalText);
                }, 1500);
            });

            // Import trigger - click hidden file input
            $('#asr-import-slots-trigger').on('click', function() {
                $('#asr-import-file-input').val('').trigger('click');
            });

            // File selected - validate and show preview modal
            $('#asr-import-file-input').on('change', function() {
                const file = this.files[0];
                if (!file) {
                    return;
                }

                // Validate extension
                if (!file.name.toLowerCase().endsWith('.json')) {
                    self.showNotice('error', asrAdmin.strings.importFailed + ': Invalid file type. Please select a .json file.');
                    $(this).val('');
                    return;
                }

                // Validate size (2MB max)
                if (file.size > 2 * 1024 * 1024) {
                    self.showNotice('error', asrAdmin.strings.importFailed + ': File too large. Maximum size is 2MB.');
                    $(this).val('');
                    return;
                }

                // Read and parse file
                const reader = new FileReader();
                reader.onload = function(e) {
                    let data;
                    try {
                        data = JSON.parse(e.target.result);
                    } catch (err) {
                        self.showNotice('error', asrAdmin.strings.importFailed + ': Invalid JSON file.');
                        return;
                    }

                    // Validate structure
                    if (!data.version || !Array.isArray(data.slots)) {
                        self.showNotice('error', asrAdmin.strings.importFailed + ': Invalid export file format.');
                        return;
                    }

                    // Populate preview modal (XSS-safe: use .text() not .html())
                    $('#asr-import-source').text(data.site_url || 'Unknown');
                    $('#asr-import-count').text(data.slot_count || data.slots.length);
                    $('#asr-import-date').text(data.export_date || 'Unknown');

                    // Build slot name list
                    const $list = $('#asr-import-slot-list').empty();
                    const maxShow = 10;
                    const slots = data.slots;

                    for (var i = 0; i < Math.min(slots.length, maxShow); i++) {
                        var slotName = (slots[i] && slots[i].name) ? slots[i].name : 'Unnamed slot';
                        $list.append($('<div>').addClass('asr-import-slot-item').text(slotName));
                    }

                    if (slots.length > maxShow) {
                        $list.append(
                            $('<div>').addClass('asr-import-slot-item asr-import-slot-more')
                                .text('...and ' + (slots.length - maxShow) + ' more')
                        );
                    }

                    // Show modal
                    $('#asr-import-modal-overlay').addClass('is-visible');
                };
                reader.readAsText(file);
            });

            // Import confirm button
            $('#asr-import-confirm').on('click', function() {
                const $btn = $(this);
                const originalText = $btn.text();
                const fileInput = $('#asr-import-file-input')[0];
                const file = fileInput.files[0];

                if (!file) {
                    self.showNotice('error', asrAdmin.strings.importFailed + ': No file selected.');
                    self.closeImportModal();
                    return;
                }

                $btn.prop('disabled', true).text(asrAdmin.strings.importing || 'Importing...');

                const formData = new FormData();
                formData.append('action', 'asr_import_slots');
                formData.append('nonce', (asrAdmin.nonces && asrAdmin.nonces.asr_import_slots) || asrAdmin.nonce);
                formData.append('import_file', file);

                $.ajax({
                    url: asrAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        self.closeImportModal();
                        if (response.success) {
                            var msg = response.data.message || (asrAdmin.strings.importSuccess || 'Import complete!');
                            // Append per-slot errors if any were skipped
                            if (response.data.errors && response.data.errors.length > 0) {
                                msg += '\n' + response.data.errors.join('\n');
                            }
                            self.showNotice('success', msg);
                            // Reload to show new slots
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            self.showNotice('error', (response.data && response.data.message) || asrAdmin.strings.importFailed);
                        }
                    },
                    error: function() {
                        self.closeImportModal();
                        self.showNotice('error', asrAdmin.strings.importFailed || 'Import failed');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text(originalText);
                    }
                });
            });

            // Close import modal
            $('.asr-import-modal-close').on('click', function() {
                self.closeImportModal();
            });

            // Close on overlay click
            $('#asr-import-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    self.closeImportModal();
                }
            });

            // Close on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#asr-import-modal-overlay').hasClass('is-visible')) {
                    self.closeImportModal();
                }
            });
        },

        /**
         * Close the import modal and reset file input
         */
        closeImportModal: function() {
            $('#asr-import-modal-overlay').removeClass('is-visible');
            $('#asr-import-file-input').val('');
        },

        /**
         * AJAX helper
         */
        ajax: function(action, data) {
            data = data || {};
            data.action = action;
            data.nonce = (asrAdmin.nonces && asrAdmin.nonces[action]) || asrAdmin.nonce;

            return $.post(asrAdmin.ajaxUrl, data);
        }
    };

    /**
     * AI Chat Module
     * Handles the floating chat modal open/close behavior and conversation management
     */
    const ASR_AI_Chat = {
        $trigger: null,
        $modal: null,
        $closeBtn: null,
        $input: null,
        $form: null,
        $messages: null,
        $sendBtn: null,
        $quickReplies: null,
        $history: null,
        $historyBtn: null,
        $historyClose: null,
        $historyList: null,
        $newConversationBtn: null,
        isOpen: false,
        isHistoryOpen: false,
        currentConversationId: null,
        conversations: [],

        /**
         * Initialize chat module
         */
        init: function() {
            this.$trigger = $('#asr-ai-chat-trigger');
            this.$modal = $('#asr-ai-chat-modal');

            // Exit early if elements don't exist
            if (!this.$trigger.length || !this.$modal.length) {
                return;
            }

            this.$closeBtn = $('#asr-ai-chat-close');
            this.$input = $('#asr-ai-chat-input');
            this.$form = $('#asr-ai-chat-form');
            this.$messages = $('#asr-ai-chat-messages');
            this.$sendBtn = $('#asr-ai-chat-send');
            this.$quickReplies = $('#asr-ai-chat-quick-replies');

            // History sidebar elements
            this.$history = $('#asr-ai-chat-history');
            this.$historyBtn = $('#asr-ai-chat-history-btn');
            this.$historyClose = $('#asr-ai-chat-history-close');
            this.$historyList = $('#asr-ai-chat-history-list');
            this.$newConversationBtn = $('#asr-ai-chat-new-conversation');

            this.bindEvents();

            // Add initial pulse animation
            this.$trigger.addClass('is-pulsing');

            // Initialize send button state
            this.updateSendButtonState();

            // Initialize size persistence (ResizeObserver)
            this.initSizePersistence();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Open modal on trigger click
            this.$trigger.on('click', function() {
                self.openModal();
            });

            // Close modal on close button click
            this.$closeBtn.on('click', function() {
                self.closeModal();
            });

            // Close modal on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.isOpen) {
                    if (self.isHistoryOpen) {
                        self.closeHistory();
                    } else {
                        self.closeModal();
                    }
                }
            });

            // Auto-resize textarea and update send button state
            this.$input.on('input', function() {
                self.autoResizeTextarea();
                self.updateSendButtonState();
            });

            // Handle form submission (placeholder for now)
            this.$form.on('submit', function(e) {
                e.preventDefault();
                self.handleSend();
            });

            // Handle Enter key (send on Enter, newline on Shift+Enter)
            this.$input.on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.handleSend();
                }
            });

            // History sidebar events
            this.$historyBtn.on('click', function() {
                self.openHistory();
            });

            this.$historyClose.on('click', function() {
                self.closeHistory();
            });

            this.$newConversationBtn.on('click', function() {
                self.newConversation();
            });

            // Delegated click handlers for conversation items
            this.$historyList.on('click', '.asr-ai-chat-conversation-item', function(e) {
                // Don't trigger if clicking delete button
                if ($(e.target).closest('.asr-ai-chat-conversation-delete').length) {
                    return;
                }
                const convId = $(this).data('conversation-id');
                self.selectConversation(convId);
            });

            this.$historyList.on('click', '.asr-ai-chat-conversation-delete', function(e) {
                e.stopPropagation();
                const convId = $(this).closest('.asr-ai-chat-conversation-item').data('conversation-id');
                self.deleteConversation(convId);
            });
        },

        /**
         * Open the chat modal
         */
        openModal: function() {
            const self = this;

            // Hide trigger
            this.$trigger.addClass('is-hidden');

            // Restore saved size before showing (desktop only)
            this.restoreModalSize();

            // Show modal with animation
            this.$modal.css('display', 'flex');

            // Trigger reflow for animation
            this.$modal[0].offsetHeight;

            this.$modal.addClass('is-visible');
            this.isOpen = true;

            // Focus input
            setTimeout(function() {
                self.$input.focus();
            }, 300);

            // Load active conversation or create new one
            this.loadOrCreateConversation();

            // Scroll messages to bottom
            this.scrollToBottom();
        },

        /**
         * Close the chat modal
         */
        closeModal: function() {
            const self = this;

            // Start close animation
            this.$modal.addClass('is-closing');
            this.$modal.removeClass('is-visible');

            // Hide after animation
            setTimeout(function() {
                self.$modal.css('display', 'none');
                self.$modal.removeClass('is-closing');
            }, 300);

            // Show trigger
            this.$trigger.removeClass('is-hidden');
            this.isOpen = false;
        },

        /**
         * Initialize modal size persistence with ResizeObserver
         */
        initSizePersistence: function() {
            const self = this;
            const STORAGE_KEY = 'asr_chat_modal_size';
            let resizeTimeout = null;

            // Only run on desktop (mobile is full-screen)
            if (window.innerWidth <= 768) {
                return;
            }

            // Create ResizeObserver to detect modal resize
            if (typeof ResizeObserver !== 'undefined') {
                this.resizeObserver = new ResizeObserver(function(entries) {
                    // Debounce: only save after user stops resizing
                    if (resizeTimeout) {
                        clearTimeout(resizeTimeout);
                    }

                    resizeTimeout = setTimeout(function() {
                        if (!self.isOpen) return;

                        const modal = entries[0].target;
                        const width = modal.offsetWidth;
                        const height = modal.offsetHeight;

                        // Only save if dimensions are reasonable
                        if (width >= 400 && height >= 400) {
                            try {
                                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                                    width: width,
                                    height: height
                                }));
                            } catch (e) {
                                // localStorage may be unavailable
                            }
                        }
                    }, 500); // Wait 500ms after resize ends
                });

                // Observe the modal element
                this.resizeObserver.observe(this.$modal[0]);
            }
        },

        /**
         * Restore saved modal size from localStorage
         */
        restoreModalSize: function() {
            const STORAGE_KEY = 'asr_chat_modal_size';

            // Don't restore on mobile
            if (window.innerWidth <= 768) {
                return;
            }

            try {
                const saved = localStorage.getItem(STORAGE_KEY);
                if (!saved) return;

                const size = JSON.parse(saved);
                if (!size.width || !size.height) return;

                // Validate against current viewport
                const maxWidth = window.innerWidth * 0.9;
                const maxHeight = window.innerHeight * 0.9;

                const width = Math.min(Math.max(size.width, 400), maxWidth);
                const height = Math.min(Math.max(size.height, 400), maxHeight);

                this.$modal.css({
                    width: width + 'px',
                    height: height + 'px'
                });
            } catch (e) {
                // Invalid JSON or localStorage unavailable
            }
        },

        /**
         * Auto-resize textarea based on content
         */
        autoResizeTextarea: function() {
            const $input = this.$input;
            $input.css('height', 'auto');
            const scrollHeight = $input[0].scrollHeight;
            const maxHeight = 120;
            $input.css('height', Math.min(scrollHeight, maxHeight) + 'px');
        },

        /**
         * Update send button disabled state based on input content
         */
        updateSendButtonState: function() {
            const hasContent = this.$input.val().trim().length > 0;
            this.$sendBtn.prop('disabled', !hasContent);
        },

        /**
         * Handle send button click
         */
        handleSend: function() {
            const message = this.$input.val().trim();

            if (!message || !this.currentConversationId) {
                return;
            }

            const self = this;

            // Hide quick replies when user sends a message
            this.hideQuickReplies();

            // Add user message to chat (optimistic)
            this.addMessage(message, 'user');

            // Clear input and update button state
            this.$input.val('');
            this.autoResizeTextarea();
            this.updateSendButtonState();

            // Show typing indicator
            this.showTypingIndicator();

            // Send message to backend
            $.post(asrAdmin.ajaxUrl, {
                action: 'asr_chat_send_message',
                nonce: (asrAdmin.nonces && asrAdmin.nonces['asr_chat_send_message']) || asrAdmin.nonce,
                conversation_id: this.currentConversationId,
                message: message
            })
            .done(function(response) {
                self.hideTypingIndicator();

                if (response.success) {
                    // Add AI response
                    self.addMessage(response.data.response, 'assistant');

                    // Show quick replies if provided
                    if (response.data.quick_replies && response.data.quick_replies.length > 0) {
                        self.showQuickReplies(response.data.quick_replies);
                    }

                    // Show recommendations if provided
                    if (response.data.recommendations && response.data.recommendations.length > 0) {
                        self.showRecommendations(response.data.recommendations);
                    }

                    // Update conversation in list
                    self.loadConversations();
                } else {
                    // Show error message
                    self.addMessage('Sorry, something went wrong. Please try again.', 'assistant');
                }
            })
            .fail(function() {
                self.hideTypingIndicator();
                self.addMessage('Network error. Please check your connection and try again.', 'assistant');
            });
        },

        /**
         * Add a message to the chat
         *
         * @param {string} content Message content
         * @param {string} role 'user' or 'assistant'
         */
        addMessage: function(content, role) {
            const self = this;

            // Split content into paragraphs on newlines
            const paragraphs = content.split('\n').filter(function(p) {
                return p.trim().length > 0;
            });

            let htmlContent = '';
            paragraphs.forEach(function(p) {
                htmlContent += '<p>' + self.escapeHtml(p) + '</p>';
            });

            const $message = $('<div class="asr-ai-chat-message asr-ai-chat-message-' + role + '">' +
                '<div class="asr-ai-chat-message-content">' +
                htmlContent +
                '</div>' +
                '</div>');

            this.$messages.append($message);
            this.scrollToBottom();
        },

        /**
         * Show typing indicator with "AI is thinking..." text
         */
        showTypingIndicator: function() {
            const $typing = $('<div class="asr-ai-chat-message asr-ai-chat-message-assistant asr-ai-chat-typing-wrapper">' +
                '<div class="asr-ai-chat-typing">' +
                '<span class="asr-ai-chat-typing-text">AI is thinking</span>' +
                '<span class="asr-ai-chat-typing-dot"></span>' +
                '<span class="asr-ai-chat-typing-dot"></span>' +
                '<span class="asr-ai-chat-typing-dot"></span>' +
                '</div>' +
                '</div>');

            this.$messages.append($typing);
            this.scrollToBottom();
        },

        /**
         * Hide typing indicator
         */
        hideTypingIndicator: function() {
            this.$messages.find('.asr-ai-chat-typing-wrapper').remove();
        },

        /**
         * Scroll messages to bottom
         */
        scrollToBottom: function() {
            const $messages = this.$messages;
            $messages.scrollTop($messages[0].scrollHeight);
        },

        /**
         * Escape HTML entities
         *
         * @param {string} text Raw text
         * @return {string} Escaped text
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Show quick-reply buttons
         *
         * @param {Array} options Array of {label: string, value: string} objects
         */
        showQuickReplies: function(options) {
            const self = this;

            if (!this.$quickReplies.length || !options || !options.length) {
                return;
            }

            // Clear existing quick replies
            this.$quickReplies.empty();

            // Create quick reply buttons
            options.forEach(function(option) {
                const $btn = $('<button type="button" class="asr-ai-chat-quick-reply"></button>');
                $btn.text(option.label);
                $btn.data('value', option.value || option.label);

                // Handle click
                $btn.on('click', function() {
                    self.handleQuickReply($(this).data('value'));
                });

                self.$quickReplies.append($btn);
            });

            // Scroll messages to show new quick replies
            this.scrollToBottom();
        },

        /**
         * Hide quick-reply buttons
         */
        hideQuickReplies: function() {
            if (this.$quickReplies.length) {
                this.$quickReplies.empty();
            }
        },

        /**
         * Handle quick-reply button click
         *
         * @param {string} value The value of the clicked quick reply
         */
        handleQuickReply: function(value) {
            // Hide quick replies
            this.hideQuickReplies();

            // Set input value and trigger send
            this.$input.val(value);
            this.updateSendButtonState();
            this.handleSend();
        },

        /**
         * Open the history sidebar
         */
        openHistory: function() {
            this.loadConversations();
            this.$history.addClass('is-visible');
            this.isHistoryOpen = true;
        },

        /**
         * Close the history sidebar
         */
        closeHistory: function() {
            this.$history.removeClass('is-visible');
            this.isHistoryOpen = false;
        },

        /**
         * Load all conversations from the server
         */
        loadConversations: function() {
            const self = this;

            $.post(asrAdmin.ajaxUrl, {
                action: 'asr_chat_get_conversations',
                nonce: (asrAdmin.nonces && asrAdmin.nonces['asr_chat_get_conversations']) || asrAdmin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    self.conversations = response.data.conversations;
                    self.currentConversationId = response.data.active_conversation_id;
                    self.renderConversationList();
                }
            });
        },

        /**
         * Render the conversation list in the sidebar
         */
        renderConversationList: function() {
            const self = this;
            this.$historyList.empty();

            if (!this.conversations.length) {
                this.$historyList.html('<div class="asr-ai-chat-history-empty">No conversations yet</div>');
                return;
            }

            this.conversations.forEach(function(conv) {
                const isActive = conv.id === self.currentConversationId;
                const title = conv.title || 'New conversation';
                const relativeTime = self.formatRelativeTime(conv.updated_at);

                const $item = $('<div class="asr-ai-chat-conversation-item' + (isActive ? ' is-active' : '') + '" data-conversation-id="' + conv.id + '">' +
                    '<div class="asr-ai-chat-conversation-content">' +
                        '<h5 class="asr-ai-chat-conversation-title">' + self.escapeHtml(title) + '</h5>' +
                        '<p class="asr-ai-chat-conversation-date">' + relativeTime + '</p>' +
                    '</div>' +
                    '<button type="button" class="asr-ai-chat-conversation-delete" aria-label="Delete conversation">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                            '<path d="M6 19C6 20.1 6.9 21 8 21H16C17.1 21 18 20.1 18 19V7H6V19ZM19 4H15.5L14.5 3H9.5L8.5 4H5V6H19V4Z" fill="currentColor"/>' +
                        '</svg>' +
                    '</button>' +
                '</div>');

                self.$historyList.append($item);
            });
        },

        /**
         * Format a date string as relative time (e.g., "2 hours ago")
         *
         * @param {string} isoString ISO date string
         * @return {string} Relative time string
         */
        formatRelativeTime: function(isoString) {
            if (!isoString) {
                return '';
            }

            const date = new Date(isoString);
            const now = new Date();
            const diffMs = now - date;
            const diffSecs = Math.floor(diffMs / 1000);
            const diffMins = Math.floor(diffSecs / 60);
            const diffHours = Math.floor(diffMins / 60);
            const diffDays = Math.floor(diffHours / 24);

            if (diffSecs < 60) {
                return 'Just now';
            } else if (diffMins < 60) {
                return diffMins + ' minute' + (diffMins === 1 ? '' : 's') + ' ago';
            } else if (diffHours < 24) {
                return diffHours + ' hour' + (diffHours === 1 ? '' : 's') + ' ago';
            } else if (diffDays < 7) {
                return diffDays + ' day' + (diffDays === 1 ? '' : 's') + ' ago';
            } else {
                return date.toLocaleDateString();
            }
        },

        /**
         * Load or create a conversation when modal opens
         */
        loadOrCreateConversation: function() {
            const self = this;

            $.post(asrAdmin.ajaxUrl, {
                action: 'asr_chat_get_conversations',
                nonce: (asrAdmin.nonces && asrAdmin.nonces['asr_chat_get_conversations']) || asrAdmin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    self.conversations = response.data.conversations;
                    const activeId = response.data.active_conversation_id;

                    if (activeId) {
                        // Load active conversation
                        self.selectConversation(activeId, true);
                    } else if (self.conversations.length > 0) {
                        // Load most recent conversation
                        self.selectConversation(self.conversations[0].id, true);
                    } else {
                        // No conversations, create one
                        self.newConversation();
                    }
                }
            });
        },

        /**
         * Select a conversation and load its messages
         *
         * @param {string} convId Conversation ID
         * @param {boolean} skipHistoryClose If true, don't close history sidebar
         */
        selectConversation: function(convId, skipHistoryClose) {
            const self = this;

            $.post(asrAdmin.ajaxUrl, {
                action: 'asr_chat_get_conversation',
                nonce: (asrAdmin.nonces && asrAdmin.nonces['asr_chat_get_conversation']) || asrAdmin.nonce,
                conversation_id: convId
            })
            .done(function(response) {
                if (response.success) {
                    self.currentConversationId = convId;
                    self.renderMessages(response.data.conversation.messages);

                    // Update active state in list
                    self.$historyList.find('.asr-ai-chat-conversation-item').removeClass('is-active');
                    self.$historyList.find('[data-conversation-id="' + convId + '"]').addClass('is-active');

                    // Close history sidebar unless skipped
                    if (!skipHistoryClose) {
                        self.closeHistory();
                    }
                }
            });
        },

        /**
         * Render messages from a conversation
         *
         * @param {Array} messages Array of message objects
         */
        renderMessages: function(messages) {
            const self = this;

            // Clear messages area but keep welcome message if no messages
            this.$messages.empty();

            if (!messages || messages.length === 0) {
                // Show welcome message
                this.$messages.html(
                    '<div class="asr-ai-chat-message asr-ai-chat-message-assistant">' +
                        '<div class="asr-ai-chat-message-content">' +
                            '<p>Hello! I\'m your AI Ad Placement Assistant. I can help you find the best locations for your ads based on IAB standards and Google best practices.</p>' +
                            '<p>Tell me about your website and I\'ll suggest optimal ad placements. For example:</p>' +
                            '<ul>' +
                                '<li>"I have a news blog and want to add display ads"</li>' +
                                '<li>"Help me set up ads for my recipe website"</li>' +
                                '<li>"What ad sizes work best for mobile?"</li>' +
                            '</ul>' +
                        '</div>' +
                    '</div>'
                );
                return;
            }

            messages.forEach(function(msg) {
                const $message = $('<div class="asr-ai-chat-message asr-ai-chat-message-' + msg.role + '">' +
                    '<div class="asr-ai-chat-message-content">' +
                        '<p>' + self.escapeHtml(msg.content) + '</p>' +
                    '</div>' +
                '</div>');

                self.$messages.append($message);
            });

            this.scrollToBottom();
        },

        /**
         * Create a new conversation and show greeting
         */
        newConversation: function() {
            const self = this;

            $.post(asrAdmin.ajaxUrl, {
                action: 'asr_chat_create_conversation',
                nonce: (asrAdmin.nonces && asrAdmin.nonces['asr_chat_create_conversation']) || asrAdmin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    self.currentConversationId = response.data.conversation_id;

                    // Clear messages area
                    self.$messages.empty();

                    // Close history sidebar
                    self.closeHistory();

                    // Fetch and show greeting message
                    self.fetchGreeting(response.data.conversation_id);

                    // Refresh conversations list
                    self.loadConversations();

                    // Focus input
                    self.$input.focus();
                }
            });
        },

        /**
         * Fetch greeting message for a conversation
         *
         * @param {string} convId Conversation ID
         */
        fetchGreeting: function(convId) {
            const self = this;

            // Show typing indicator
            this.showTypingIndicator();

            $.post(asrAdmin.ajaxUrl, {
                action: 'asr_chat_get_greeting',
                nonce: (asrAdmin.nonces && asrAdmin.nonces['asr_chat_get_greeting']) || asrAdmin.nonce,
                conversation_id: convId
            })
            .done(function(response) {
                self.hideTypingIndicator();

                if (response.success) {
                    // Add greeting message
                    self.addMessage(response.data.response, 'assistant');

                    // Show quick replies
                    if (response.data.quick_replies && response.data.quick_replies.length > 0) {
                        self.showQuickReplies(response.data.quick_replies);
                    }
                }
            })
            .fail(function() {
                self.hideTypingIndicator();
                // Fallback to static greeting
                self.addMessage("Hi! I'm here to help you find the best ad placements for your website. Tell me about your site and I'll suggest optimal placements.", 'assistant');
            });
        },

        /**
         * Impression multipliers based on ad position research.
         * Values represent viewability percentage (0-1) per 1000 pageviews.
         */
        impressionMultipliers: {
            'header': { desktop: 0.85, mobile: 0.80, label: 'High visibility' },
            'above-fold': { desktop: 1.0, mobile: 0.95, label: 'Highest viewability' },
            'above-content': { desktop: 0.90, mobile: 0.85, label: 'Very high visibility' },
            'in-content-1': { desktop: 0.75, mobile: 0.70, label: 'Good engagement' },
            'in-content-2': { desktop: 0.55, mobile: 0.50, label: 'Moderate engagement' },
            'sidebar': { desktop: 0.65, mobile: 0, label: 'Desktop only' },
            'sidebar-sticky': { desktop: 0.70, mobile: 0, label: 'Sticky sidebar' },
            'below-fold': { desktop: 0.47, mobile: 0.40, label: 'Lower viewability' },
            'footer': { desktop: 0.30, mobile: 0.25, label: 'Footer position' },
            'default': { desktop: 0.50, mobile: 0.45, label: 'Standard position' }
        },

        /**
         * Stored recommendations for slot creation
         */
        currentRecommendations: [],

        /**
         * Show recommendations as a simple card list
         *
         * @param {Array} recommendations Array of recommendation objects
         */
        showRecommendations: function(recommendations) {
            const self = this;

            if (!recommendations || recommendations.length === 0) {
                return;
            }

            // Store recommendations for later use
            this.currentRecommendations = recommendations;

            // Create recommendations container
            const $container = $(this.createRecommendationsHtml(recommendations));

            // Bind checkbox changes
            $container.find('.asr-chat-placement-checkbox').on('change', function() {
                const index = $(this).data('index');
                const isChecked = $(this).is(':checked');
                const $card = $container.find('.asr-ai-chat-recommendation-item[data-index="' + index + '"]');

                if (isChecked) {
                    $card.removeClass('rejected');
                } else {
                    $card.addClass('rejected');
                }

                self.updateRecommendationsSummary($container, recommendations);
            });

            // Bind apply button
            $container.find('.asr-chat-apply-recommendations').on('click', function() {
                self.applyRecommendations(recommendations, $container);
            });

            // Bind skip button
            $container.find('.asr-chat-skip-recommendations').on('click', function() {
                $container.slideUp(function() {
                    $(this).remove();
                });
                self.addMessage("No problem! You can manually configure your ad slots in the Slots tab whenever you're ready.", 'assistant');
            });

            this.$messages.append($container);

            // Initialize summary
            this.updateRecommendationsSummary($container, recommendations);

            this.scrollToBottom();
        },

        /**
         * Create HTML for recommendations card list
         *
         * @param {Array} recommendations Array of recommendation objects
         * @return {string} HTML string
         */
        createRecommendationsHtml: function(recommendations) {
            const self = this;

            let html = '<div class="asr-recommendations-container">';
            html += '<div class="asr-recommendations-header">';
            html += '<h4>Here are my recommendations:</h4>';
            html += '</div>';

            // Summary line
            html += '<div class="asr-chat-recommendations-summary" id="asr-chat-summary"></div>';

            // Placement cards
            html += '<div class="asr-ai-chat-recommendations-list">';
            recommendations.forEach(function(rec, index) {
                html += self.createRecommendationCardHtml(rec, index);
            });
            html += '</div>';

            // Action buttons
            html += '<div class="asr-chat-recommendations-actions">';
            html += '<button type="button" class="button button-primary asr-chat-apply-recommendations">';
            html += 'Create Selected Slots';
            html += '</button>';
            html += '<button type="button" class="button asr-chat-skip-recommendations">Skip</button>';
            html += '</div>';

            html += '</div>';

            return html;
        },

        /**
         * Create HTML for a single recommendation card
         *
         * @param {Object} rec Recommendation object
         * @param {number} index Index
         * @return {string} HTML string
         */
        createRecommendationCardHtml: function(rec, index) {
            const slotDevice = (rec.device || 'both').toLowerCase();
            const deviceIcon = slotDevice === 'desktop' ? '💻' : (slotDevice === 'mobile' ? '📱' : '🖥️📱');

            let badges = '';
            if (rec.sticky) {
                badges += '<span class="asr-badge asr-badge-sticky">Sticky</span>';
            }

            let html = '<div class="asr-ai-chat-recommendation-item" data-index="' + index + '">';
            html += '<div class="asr-ai-chat-recommendation-header">';
            html += '<label>';
            html += '<input type="checkbox" class="asr-recommendation-checkbox asr-chat-placement-checkbox" checked data-index="' + index + '">';
            html += '<span class="asr-recommendation-name">' + this.escapeHtml(rec.name) + '</span>';
            html += '</label>';
            html += '<span class="asr-recommendation-device">' + deviceIcon + '</span>';
            html += badges;
            html += '</div>';
            html += '<div class="asr-ai-chat-recommendation-details">';
            html += '<code>' + this.escapeHtml(rec.selector) + '</code>';
            html += '<span class="asr-recommendation-size">' + (rec.width || 300) + '×' + (rec.height || 250) + 'px</span>';
            html += '</div>';
            if (rec.rationale) {
                html += '<p class="asr-ai-chat-recommendation-rationale">' + this.escapeHtml(rec.rationale) + '</p>';
            }
            html += '</div>';

            return html;
        },

        /**
         * Detect position category for impression estimation
         *
         * @param {Object} rec Recommendation object
         * @return {string} Position category
         */
        detectPositionCategory: function(rec) {
            const selector = (rec.selector || '').toLowerCase();
            const name = (rec.name || '').toLowerCase();

            if (selector.includes('header') || name.includes('header') || name.includes('leaderboard')) {
                return 'header';
            }
            if (name.includes('above-content') || name.includes('below-header')) {
                return 'above-content';
            }
            if (selector.includes('sidebar') || name.includes('sidebar')) {
                return rec.sticky ? 'sidebar-sticky' : 'sidebar';
            }
            if (selector.includes('footer') || name.includes('footer')) {
                return 'footer';
            }
            if (name.includes('in-content') || name.includes('mid-article')) {
                return name.includes('1') || name.includes('first') ? 'in-content-1' : 'in-content-2';
            }
            return 'default';
        },

        /**
         * Estimate impressions for a recommendation
         *
         * @param {Object} rec Recommendation object
         * @return {Object} Impression estimates
         */
        estimateImpressions: function(rec) {
            const baseImpressions = 1000;
            const position = this.detectPositionCategory(rec);
            const mult = this.impressionMultipliers[position] || this.impressionMultipliers['default'];

            return {
                desktop: Math.round(baseImpressions * mult.desktop),
                mobile: Math.round(baseImpressions * mult.mobile),
                label: mult.label
            };
        },

        /**
         * Update recommendations summary
         *
         * @param {jQuery} $container Recommendations container
         * @param {Array} recommendations Recommendations array
         */
        updateRecommendationsSummary: function($container, recommendations) {
            const $summary = $container.find('#asr-chat-summary');
            const $checkboxes = $container.find('.asr-chat-placement-checkbox:checked');
            const selectedCount = $checkboxes.length;
            const totalCount = recommendations.length;

            $summary.html(
                '<strong>' + selectedCount + '</strong> of ' + totalCount + ' placements selected'
            );

            // Update button text
            const $btn = $container.find('.asr-chat-apply-recommendations');
            if (selectedCount > 0) {
                $btn.prop('disabled', false).text('Create ' + selectedCount + ' Slot' + (selectedCount > 1 ? 's' : ''));
            } else {
                $btn.prop('disabled', true).text('Select Placements');
            }
        },

        /**
         * Apply selected recommendations as slots
         *
         * @param {Array} recommendations All recommendations
         * @param {jQuery} $container Recommendations container
         */
        applyRecommendations: function(recommendations, $container) {
            const self = this;
            $container = $container || this.$messages.find('.asr-recommendations-container');
            const $checkboxes = $container.find('.asr-chat-placement-checkbox:checked');
            const selectedIndices = [];

            $checkboxes.each(function() {
                selectedIndices.push($(this).data('index'));
            });

            if (selectedIndices.length === 0) {
                alert('Please select at least one placement to apply.');
                return;
            }

            // Filter to selected recommendations
            const toApply = recommendations.filter(function(rec, index) {
                return selectedIndices.includes(index);
            });

            // Show applying state
            const $btn = $container.find('.asr-chat-apply-recommendations');
            $btn.prop('disabled', true).text('Creating...');

            // Create slots one by one
            let completed = 0;
            const errors = [];
            const createdSlots = [];

            toApply.forEach(function(rec) {
                const slotData = {
                    action: 'asr_save_slot',
                    nonce: (asrAdmin.nonces && asrAdmin.nonces['asr_save_slot']) || asrAdmin.nonce,
                    name: rec.name,
                    selector: rec.selector,
                    placement: rec.position || 'after',
                    device: rec.device || 'both',
                    min_height: rec.height,
                    type: 'custom',
                    is_sticky: rec.sticky ? '1' : '0',
                    margin_top: 10,
                    margin_bottom: 10
                };

                $.post(asrAdmin.ajaxUrl, slotData)
                .done(function(response) {
                    completed++;
                    if (response.success) {
                        createdSlots.push(rec.name);
                    } else {
                        errors.push(rec.name);
                    }
                    checkComplete();
                })
                .fail(function() {
                    completed++;
                    errors.push(rec.name);
                    checkComplete();
                });
            });

            function checkComplete() {
                if (completed === toApply.length) {
                    // Update container to show completed state
                    $container.find('.asr-chat-recommendations-actions').html(
                        '<div class="asr-chat-created-badge">Created ' + createdSlots.length + ' slot' + (createdSlots.length !== 1 ? 's' : '') + '</div>'
                    );

                    // Disable all checkboxes
                    $container.find('.asr-chat-placement-checkbox').prop('disabled', true);

                    // Add completed class to container
                    $container.addClass('is-completed');

                    // Add success message
                    if (errors.length === 0) {
                        self.addMessage('Created ' + createdSlots.length + ' ad slot' + (createdSlots.length > 1 ? 's' : '') + '!\n\nYou can review them in the Slots tab, then generate and deploy the code.', 'assistant');
                    } else {
                        self.addMessage('Created ' + createdSlots.length + ' slot' + (createdSlots.length !== 1 ? 's' : '') + '. ' + errors.length + ' failed: ' + errors.join(', '), 'assistant');
                    }

                    // Show quick reply to go to slots
                    self.showQuickReplies([
                        { label: 'View my slots', value: 'show_slots' }
                    ]);

                    // If quick reply is clicked, scroll to slots section
                    self.$quickReplies.off('click', '.asr-ai-chat-quick-reply').on('click', '.asr-ai-chat-quick-reply', function() {
                        const value = $(this).data('value');
                        if (value === 'show_slots') {
                            self.hideQuickReplies();
                            // Close modal and scroll to slots tab
                            self.closeModal();
                            // Click on slots tab if visible
                            setTimeout(function() {
                                const $slotsTab = $('a[href="#asr-slots-tab"]');
                                if ($slotsTab.length) {
                                    $slotsTab.click();
                                }
                                // Scroll to configured slots
                                const $configuredSlots = $('#asr-configured-slots');
                                if ($configuredSlots.length) {
                                    $('html, body').animate({
                                        scrollTop: $configuredSlots.offset().top - 50
                                    }, 300);
                                }
                            }, 350);
                        }
                    });
                }
            }
        },


        /**
         * Show a brief toast notification
         *
         * @param {string} message Toast message
         */
        showToast: function(message) {
            const $toast = $('<div class="asr-toast">' + this.escapeHtml(message) + '</div>');
            $('body').append($toast);

            setTimeout(function() {
                $toast.addClass('is-visible');
            }, 10);

            setTimeout(function() {
                $toast.removeClass('is-visible');
                setTimeout(function() {
                    $toast.remove();
                }, 300);
            }, 2000);
        },

        /**
         * Delete a conversation
         *
         * @param {string} convId Conversation ID
         */
        deleteConversation: function(convId) {
            const self = this;

            if (!confirm('Delete this conversation?')) {
                return;
            }

            $.post(asrAdmin.ajaxUrl, {
                action: 'asr_chat_delete_conversation',
                nonce: (asrAdmin.nonces && asrAdmin.nonces['asr_chat_delete_conversation']) || asrAdmin.nonce,
                conversation_id: convId
            })
            .done(function(response) {
                if (response.success) {
                    // Remove from list
                    self.$historyList.find('[data-conversation-id="' + convId + '"]').remove();

                    // Update local conversations array
                    self.conversations = self.conversations.filter(function(c) {
                        return c.id !== convId;
                    });

                    // If deleted the current conversation, load another or create new
                    if (self.currentConversationId === convId) {
                        self.currentConversationId = null;
                        if (self.conversations.length > 0) {
                            self.selectConversation(self.conversations[0].id);
                        } else {
                            self.newConversation();
                        }
                    }

                    // Show empty state if no conversations left
                    if (self.conversations.length === 0) {
                        self.$historyList.html('<div class="asr-ai-chat-history-empty">No conversations yet</div>');
                    }
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        ASR_Admin.init();
        ASR_AI_Chat.init();
    });

})(jQuery);
