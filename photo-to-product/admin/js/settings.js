/* global jQuery, AIPI_PRO_SETTINGS */

// Admin settings page script for AI Product Importer Pro.
// Handles testing the BYO OpenAI API key via AJAX. Registration now uses
// a normal admin-post request so it remains reliable under stricter CSP
// policies and admin script conflicts.
jQuery(function ($) {
    const $btn = $('#aipi-test-byo-key');
    const $result = $('#aipi-test-byo-result');

    function getConfig($el) {
        const ajaxUrl = $el.data('ajax-url') || (window.AIPI_PRO_SETTINGS && AIPI_PRO_SETTINGS.ajaxUrl) || window.ajaxurl || '';
        const nonce = $el.data('nonce') || (window.AIPI_PRO_SETTINGS && AIPI_PRO_SETTINGS.nonce) || '';
        return { ajaxUrl, nonce };
    }

    if ($btn.length) {
        $btn.on('click', function (event) {
            event.preventDefault();
            const config = getConfig($btn);
            if (!config.ajaxUrl || !config.nonce) {
                $result.text(String('Settings page configuration is missing. Refresh the page and try again.'));
                $result.removeClass('aipi-test-success').addClass('aipi-test-error');
                return;
            }

            $result.text(String('Testing…')).removeClass('aipi-test-success aipi-test-error');
            $btn.prop('disabled', true);
            $.post(
                config.ajaxUrl,
                {
                    action: 'aipi_test_byo_key',
                    nonce: config.nonce,
                },
                function (response) {
                    if (response && response.success) {
                        $result.text(String(response.data && response.data.message ? response.data.message : 'Key validated successfully.'));
                        $result.removeClass('aipi-test-error').addClass('aipi-test-success');
                    } else {
                        const msg = response && response.data && response.data.message ? response.data.message : 'The API key could not be validated.';
                        $result.text(String(msg));
                        $result.removeClass('aipi-test-success').addClass('aipi-test-error');
                    }
                }
            ).fail(function () {
                $result.text(String('An error occurred while validating the API key.'));
                $result.removeClass('aipi-test-success').addClass('aipi-test-error');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    }

    // Handle reveal/hide of recovery credentials and copying them to clipboard.
    const $revealBtn = $('#aipi-reveal-credentials');
    const $copyBtn = $('#aipi-copy-credentials');
    const $copyResult = $('#aipi-copy-result');
    let credentialsRevealed = false;
    function toggleCredentials() {
        const inputs = $('#aipi-current-customer-id, #aipi-current-installation-id, #aipi-current-installation-token');
        credentialsRevealed = !credentialsRevealed;
        inputs.each(function () {
            const $input = $(this);
            if (credentialsRevealed) {
                $input.attr('type', 'text');
            } else {
                $input.attr('type', 'password');
            }
        });
        const showLabel = $revealBtn.data('show-label') || 'Reveal';
        const hideLabel = $revealBtn.data('hide-label') || 'Hide';
        $revealBtn.text(credentialsRevealed ? hideLabel : showLabel);
    }
    if ($revealBtn.length) {
        $revealBtn.on('click', function (event) {
            event.preventDefault();
            toggleCredentials();
        });
    }
    if ($copyBtn.length) {
        $copyBtn.on('click', function (event) {
            event.preventDefault();
            const customer = $('#aipi-current-customer-id').val() || '';
            const installation = $('#aipi-current-installation-id').val() || '';
            const token = $('#aipi-current-installation-token').val() || '';
            const text = 'Customer ID: ' + customer + '\nInstallation ID: ' + installation + '\nInstallation Token: ' + token;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    if ($copyResult.length) { $copyResult.text('Copied.').removeClass('aipi-test-error').addClass('aipi-test-success'); }
                }).catch(function () {
                    if ($copyResult.length) { $copyResult.text('Unable to copy.').removeClass('aipi-test-success').addClass('aipi-test-error'); }
                });
            } else {
                // Fallback for older browsers: create a temporary textarea
                const $temp = $('<textarea>').css({ position: 'absolute', left: '-9999px', top: '-9999px' }).appendTo('body');
                $temp.val(text).select();
                try {
                    document.execCommand('copy');
                    if ($copyResult.length) { $copyResult.text('Copied.').removeClass('aipi-test-error').addClass('aipi-test-success'); }
                } catch (err) {
                    if ($copyResult.length) { $copyResult.text('Unable to copy.').removeClass('aipi-test-success').addClass('aipi-test-error'); }
                }
                $temp.remove();
            }
        });
    }
});
