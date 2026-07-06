/**
 * Hubbee Admin JavaScript
 *
 * @package Hubbee
 */

(function($) {
    'use strict';

    /**
     * Settings Page Handler
     */
    // Show a "still working / retry" hint when an action runs longer than this.
    var WORKING_HINT_DELAY = 5000;

    var BzSettings = {
        // Timer handles for the long-running working hints, keyed by hint id.
        _hintTimers: {},

        init: function() {
            this.bindEvents();
        },

        /**
         * Start a timer that reveals a "still working" hint if the action
         * has not completed within WORKING_HINT_DELAY.
         */
        startWorkingHint: function(hintId) {
            BzSettings.clearWorkingHint(hintId);
            BzSettings._hintTimers[hintId] = window.setTimeout(function() {
                $('#' + hintId).removeClass('hidden');
            }, WORKING_HINT_DELAY);
        },

        /**
         * Cancel the pending timer and hide the hint.
         */
        clearWorkingHint: function(hintId) {
            if (BzSettings._hintTimers[hintId]) {
                window.clearTimeout(BzSettings._hintTimers[hintId]);
                delete BzSettings._hintTimers[hintId];
            }
            $('#' + hintId).addClass('hidden');
        },

        bindEvents: function() {
            $('#bz-connect-btn').on('click', this.handleConnect);
            $('#bz-disconnect-btn').on('click', this.handleDisconnect);
            $('#bz-test-connection-btn').on('click', this.handleTestConnection);
        },

        handleConnect: function(e) {
            e.preventDefault();

            var token = $('#bz-onboarding-token').val().trim();

            if (!token) {
                BzSettings.showMessage('error', bzAdmin.strings.enter_token);
                return;
            }

            var $btn = $(this);
            var $spinner = $('#bz-connect-spinner');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            BzSettings.hideMessage();
            BzSettings.startWorkingHint('bz-connect-working-hint');

            $.ajax({
                url: bzAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bz_enroll',
                    nonce: bzAdmin.nonce,
                    onboarding_token: token
                },
                success: function(response) {
                    if (response.success) {
                        BzSettings.showMessage('success', response.data.message || bzAdmin.strings.connected);
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        BzSettings.showMessage('error', response.data.message || bzAdmin.strings.connection_failed);
                        $btn.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    BzSettings.showMessage('error', bzAdmin.strings.error_occurred);
                    $btn.prop('disabled', false);
                },
                complete: function() {
                    $spinner.removeClass('is-active');
                    BzSettings.clearWorkingHint('bz-connect-working-hint');
                }
            });
        },

        handleDisconnect: function(e) {
            e.preventDefault();

            if (!confirm(bzAdmin.strings.confirm_disconnect)) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: bzAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bz_disconnect',
                    nonce: bzAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || bzAdmin.strings.disconnect_failed);
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert(bzAdmin.strings.error_occurred);
                    $btn.prop('disabled', false);
                }
            });
        },

        showMessage: function(type, message) {
            $('#bz-connect-message')
                .removeClass('hidden success error')
                .addClass(type)
                .text(message);
        },

        hideMessage: function() {
            $('#bz-connect-message').addClass('hidden');
        },

        handleTestConnection: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $spinner = $('#bz-test-spinner');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            BzSettings.hideTestMessage();
            BzSettings.startWorkingHint('bz-test-working-hint');

            $.ajax({
                url: bzAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bz_test_connection',
                    nonce: bzAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BzSettings.showTestMessage('success', response.data.message);
                    } else {
                        BzSettings.showTestMessage('error', response.data.message || 'Verbindungstest fehlgeschlagen.');
                    }
                },
                error: function() {
                    BzSettings.showTestMessage('error', bzAdmin.strings.error_occurred);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    BzSettings.clearWorkingHint('bz-test-working-hint');
                }
            });
        },

        showTestMessage: function(type, message) {
            $('#bz-test-message')
                .removeClass('hidden success error')
                .addClass(type)
                .text(message);
        },

        hideTestMessage: function() {
            $('#bz-test-message').addClass('hidden');
        }
    };

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Initialize settings handlers
        if ($('#bz-connect-btn').length || $('#bz-disconnect-btn').length || $('#bz-test-connection-btn').length) {
            BzSettings.init();
        }
    });

})(jQuery);
