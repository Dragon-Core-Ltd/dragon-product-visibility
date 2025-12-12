(function($) {
    'use strict';

    /**
     * Dragon Product Visibility Admin JS
     *
     * @package DragonProductVisibility
     */
    var DPV_Admin = {

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initSelect2();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Toggle restrictions panel based on mode
            $('#dpv_restriction_mode').on('change', this.toggleRestrictionsPanel);
        },

        /**
         * Toggle restrictions panel visibility and update labels
         */
        toggleRestrictionsPanel: function() {
            var mode = $(this).val();
            var $panel = $('.dpv-restrictions-panel');

            if (mode === 'none') {
                $panel.slideUp(200);
            } else {
                $panel.slideDown(200);
                // Update labels based on mode
                DPV_Admin.updateModeLabels(mode);
            }
        },

        /**
         * Update labels based on whitelist/blacklist mode
         */
        updateModeLabels: function(mode) {
            var $whitelistText = $('.dpv-whitelist-text');
            var $blacklistText = $('.dpv-blacklist-text');

            if (mode === 'blacklist') {
                $whitelistText.hide();
                $blacklistText.show();
            } else {
                $whitelistText.show();
                $blacklistText.hide();
            }
        },

        /**
         * Initialize Select2 components
         */
        initSelect2: function() {
            // Customer select with AJAX search
            $('.dpv-customer-select').select2({
                placeholder: dpv_admin.i18n.search_customers,
                allowClear: true,
                minimumInputLength: 1,
                ajax: {
                    url: dpv_admin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'dpv_search_customers',
                            search_term: params.term,
                            nonce: dpv_admin.nonce
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                },
                language: {
                    noResults: function() {
                        return dpv_admin.i18n.no_results;
                    },
                    searching: function() {
                        return dpv_admin.i18n.searching;
                    }
                }
            });

            // Role select
            $('.dpv-role-select').select2({
                placeholder: dpv_admin.i18n.select_roles,
                allowClear: true
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        DPV_Admin.init();
    });

})(jQuery);
