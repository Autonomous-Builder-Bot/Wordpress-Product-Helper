/* global jQuery, AIPI_PRO */
jQuery(function ($) {
    const jobListEl = $('#aipi-pro-job-list');
    const noticeEl = $('#aipi-pro-notice');
    let currentPage = 1;
    let totalPages = 1;
    const perPage = 20;
    let noticeTimer = null;

    /**
     * Display a transient notice to the administrator. Notices are
     * automatically cleared after a short delay. Types control the
     * styling via CSS (.notice-success, .notice-error).
     * @param {string} msg Message to display
     * @param {string} type Either 'success' or 'error'
     */
    function showNotice(msg, type = 'info') {
        if (!noticeEl.length) {
            return;
        }
        // Reset classes and content
        noticeEl.stop(true, true)
            .removeClass('notice-success notice-error notice-info')
            .addClass('notice-' + type)
            .text(String(msg))
            .show();
        if (noticeTimer) {
            clearTimeout(noticeTimer);
        }
        // Fade out after 5 seconds
        noticeTimer = setTimeout(function () {
            noticeEl.fadeOut(400);
            noticeTimer = null;
        }, 5000);
    }

    function clearNotice() {
        if (!noticeEl.length) {
            return;
        }
        if (noticeTimer) {
            clearTimeout(noticeTimer);
            noticeTimer = null;
        }
        noticeEl.hide().text('').removeClass('notice-success notice-error notice-info');
    }

    function renderAccountStatus(balanceText) {
        const modeLabel = AIPI_PRO.accountMode === 'byo_key' ? 'BYO API Key' : 'Managed Mode';
        const byo = AIPI_PRO.accountMode === 'byo_key' ? (AIPI_PRO.byoKeyConfigured ? 'Ready' : 'Finish setup') : '';
        const connected = AIPI_PRO.accountMode === 'managed' ? (AIPI_PRO.connectionStatus === 'configured' ? 'Connected' : 'Finish setup') : byo;
        return '<div class="aipi-account-status"><span class="aipi-status-pill ' + (connected === 'Connected' || connected === 'Ready' ? 'is-good' : 'is-muted') + '">' + escHtml(connected) + '</span><span class="aipi-account-copy"><strong>' + escHtml(modeLabel) + '</strong>' + (balanceText ? ' · ' + escHtml(balanceText) + ' credits available' : '') + '</span><a href="' + escHtml(AIPI_PRO.settingsUrl) + '">Open Connection</a></div>';
    }

    function loadBalance() {
        if (AIPI_PRO.accountMode !== 'managed') {
            return;
        }
        $.post(AIPI_PRO.ajaxUrl, { action: 'aipi_get_balance', nonce: AIPI_PRO.nonce }, function (response) {
            if (response.success) {
                const balance = response.data.balance ?? response.data.credits ?? response.data.available_credits ?? '';
                $('.aipi-account-status').replaceWith(renderAccountStatus(balance !== '' ? String(balance) : 'unknown'));
            }
        }).fail(function () {
            $('.aipi-account-status').replaceWith(renderAccountStatus('unavailable'));
        });
    }

    // Escape HTML to prevent injection when rendering listing data.
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }



    function formatStatus(status) {
        const map = {
            draft: 'Draft',
            photos_uploaded: 'Photos Added',
            ready_for_generation: 'Ready to Draft',
            generating: 'Generating',
            generated: 'Ready to Review',
            creating_product: 'Creating WooCommerce Draft',
            completed: 'Completed',
            failed: 'Needs Review'
        };
        return map[status] || String(status || '').replace(/_/g, ' ');
    }

    function statusClass(status) {
        if (status === 'completed' || status === 'generated') return 'is-good';
        if (status === 'failed') return 'is-bad';
        return 'is-muted';
    }

    function getNextStepText(job) {
        const status = job.status;
        if (status === 'draft') return 'Add photos or continue with a description.';
        if (status === 'photos_uploaded') return 'Add notes if useful, then prepare the draft.';
        if (status === 'ready_for_generation') return 'Review your notes, then generate the draft.';
        if (status === 'generating') return 'Your draft is being prepared.';
        if (status === 'generated') return 'Review the draft, then create the WooCommerce product.';
        if (status === 'creating_product') return 'Creating the WooCommerce draft product.';
        if (status === 'completed') return 'The WooCommerce draft is ready to edit.';
        if (status === 'failed') return 'Review the message below and reset when ready.';
        return 'Continue when ready.';
    }


    // Load jobs from the server and render them.
    function loadJobs(page) {
        if (page) {
            currentPage = Math.max(1, parseInt(page, 10) || 1);
        }
        clearNotice();
        $.post(
            AIPI_PRO.ajaxUrl,
            {
                action: 'aipi_list_jobs',
                nonce: AIPI_PRO.nonce,
                page: currentPage,
                per_page: perPage,
            },
            function (response) {
                if (!response.success) {
                    jobListEl.html('<p class="error">' + escHtml(response.data.message) + '</p>');
                    return;
                }
                const jobs = response.data.jobs;
                totalPages = Math.max(1, parseInt(response.data.total_pages || 1, 10));
                if (jobs.length === 0) {
                    jobListEl.html(renderAccountStatus('') + '<div class="aipi-empty-state"><h3>' + escHtml('No product drafts yet') + '</h3><p>' + escHtml('Create one product or use Bulk Create to turn a batch of photos into drafts.') + '</p></div>' + renderPagination(currentPage, totalPages));
                    loadBalance();
                    return;
                }
                const rows = jobs
                    .map(function (job) {
                        return renderJobRow(job);
                    })
                    .join('');
                jobListEl.html(renderAccountStatus('') + rows + renderPagination(currentPage, totalPages));
                loadBalance();
            }
        ).fail(function () {
            jobListEl.html(renderAccountStatus('') + '<p class="error">' + escHtml('We could not load your drafts. Refresh the page and try again. If the problem continues, check your connection and plugin logs.') + '</p>');
        });
    }


    function renderPagination(page, totalPages) {
        totalPages = Math.max(1, parseInt(totalPages || 1, 10));
        if (totalPages <= 1) {
            return '';
        }
        let html = '<div class="aipi-pagination" style="margin-top:12px;display:flex;gap:8px;align-items:center;">';
        html += '<button class="button aipi-page-prev"' + (page <= 1 ? ' disabled="disabled"' : '') + '>Previous</button>';
        html += '<span>Page ' + page + ' of ' + totalPages + '</span>';
        html += '<button class="button aipi-page-next"' + (page >= totalPages ? ' disabled="disabled"' : '') + '>Next</button>';
        html += '</div>';
        return html;
    }

    // Render a single job row into an HTML string.
    function renderJobRow(job) {
        let html = '<div class="aipi-job" data-job-id="' + job.id + '">';
        html += '<div class="aipi-job-head"><div><span class="aipi-eyebrow">Product Draft</span><h2>' + escHtml(formatStatus(job.status)) + '</h2><span class="aipi-job-id">#' + job.id + '</span></div><span class="aipi-status-pill ' + statusClass(job.status) + '">' + escHtml(formatStatus(job.status)) + '</span></div>';
        html += '<div class="aipi-job-meta"><span>' + escHtml(job.attachments + ' photo' + (job.attachments === 1 ? '' : 's')) + '</span></div>';
        html += '<div class="aipi-next-step">' + escHtml(getNextStepText(job)) + '</div>';
        // Show creation and last updated timestamps when available. Fall back to empty strings.
        if (job.createdAt) {
            try {
                const createdDate = new Date(job.createdAt);
                html += '<p>Created: ' + escHtml(createdDate.toLocaleString()) + '</p>';
            } catch (err) {
                html += '<p>Created: ' + escHtml(job.createdAt) + '</p>';
            }
        }
        if (job.updatedAt) {
            try {
                const updatedDate = new Date(job.updatedAt);
                html += '<p>Updated: ' + escHtml(updatedDate.toLocaleString()) + '</p>';
            } catch (err) {
                html += '<p>Updated: ' + escHtml(job.updatedAt) + '</p>';
            }
        }
        if (job.error) {
            html += '<p class="error">' + escHtml(job.error) + '</p>';
        }
        if (job.ledgerWarning) {
            html += '<p class="error">' + escHtml(job.ledgerWarning) + '</p>';
        }
        if (job.taxonomyWarning) {
            html += '<p class="error">' + escHtml(job.taxonomyWarning) + '</p>';
        }
        switch (job.status) {
            case 'draft':
            case 'photos_uploaded':
                html += '<input type="file" accept="image/*" multiple class="aipi-photo-input" />';
                html += '<button class="button aipi-upload">Add Photos</button>';
                html += '<button class="button aipi-ready">Prepare Draft</button>';
                break;
            case 'ready_for_generation':
                html += '<label class="aipi-field-label">Notes for this product</label><textarea placeholder="Add a short description, brand, condition, or anything the AI should know" class="aipi-description"></textarea>';
                // Allow the user to choose whether to include images in the AI request. Default to checked.
                html += '<label class="aipi-use-images-label" style="display:block;margin:4px 0;">' +
                    '<input type="checkbox" class="aipi-use-images" checked="checked" /> ' + escHtml('Use photos for this draft') +
                    '</label>';
                html += '<button class="button button-primary aipi-generate">' + escHtml('Prepare Draft') + '</button>';
                break;
            case 'generating':
                html += '<p class="aipi-progress-copy">' + escHtml('Preparing your product draft…') + '</p>';
                break;
            case 'generated':
                if (job.error) {
                    html += '<p class="error">' + escHtml(job.error) + '</p>';
                }
                if (job.listing && Object.keys(job.listing).length > 0) {
                    html += '<div class="aipi-listing"><div class="aipi-listing-head"><h3>' + escHtml('Review Draft') + '</h3><p>' + escHtml('Check the suggested details before creating the WooCommerce draft.') + '</p></div>';
                    html += '<p><strong>Title:</strong> ' + escHtml(job.listing.title || '') + '</p>';
                    html += '<p><strong>Short Description:</strong> ' + escHtml(job.listing.short_description || '') + '</p>';
                    html += '<p><strong>Long Description:</strong> ' + escHtml(job.listing.long_description || job.listing.description || '') + '</p>';
                    if (job.listing.bullet_features && job.listing.bullet_features.length) {
                        html += '<p><strong>Bullet Features:</strong> ' + escHtml(job.listing.bullet_features.join('; ')) + '</p>';
                    }
                    html += '<p><strong>Condition Notes:</strong> ' + escHtml(job.listing.condition_notes || '') + '</p>';
                    html += '<p><strong>SEO Meta:</strong> ' + escHtml(job.listing.seo_meta_description || '') + '</p>';
                    html += '<p><strong>Categories:</strong> ' + escHtml((job.listing.categories || []).join(', ')) + '</p>';
                    html += '<p><strong>Tags:</strong> ' + escHtml((job.listing.tags || []).join(', ')) + '</p>';
                    html += '<p><strong>Brand:</strong> ' + escHtml(job.listing.brand || '') + '</p>';
                    html += '<p><strong>Condition:</strong> ' + escHtml(job.listing.condition || '') + '</p>';
                    if (job.listing.confidence_notes && job.listing.confidence_notes.length) {
                        html += '<p><strong>Notes:</strong> ' + escHtml(job.listing.confidence_notes.join('; ')) + '</p>';
                    }
                    html += '</div>';
                }
                html += '<button class="button button-primary aipi-create-product">Create WooCommerce Draft</button>';
                break;
            case 'creating_product':
                html += '<p class="aipi-progress-copy">' + escHtml('Creating your WooCommerce draft product…') + '</p>';
                break;
            case 'completed':
                html += '<p>' + escHtml('WooCommerce draft created') + ': #' + job.productId + '</p>';
                if (job.editUrl) {
                    html += '<a href="' + escHtml(job.editUrl) + '" target="_blank" rel="noopener noreferrer">Edit Product</a>';
                }
                break;
            case 'failed':
                html += '<p class="error">' + escHtml(job.error || 'This draft needs attention.') + '</p>';
                // Display a reset button that prepares the job for another generation attempt if retryable.
                if (job.canRetry) {
                    html += '<button class="button aipi-retry">Prepare Again</button>';
                }
                break;
        }
        html += '<button class="button aipi-delete">Delete</button>';
        html += '</div>';
        return html;
    }

    // Helper: run an AJAX call with spinner feedback on a button. Disables
    // the button, appends a spinner and executes the provided request. On
    // completion, re-enables the button and removes the spinner. Optionally
    // displays a notice for errors.
    function ajaxWithSpinner($btn, requestFn) {
        const spinner = $('<span class="spinner is-active" style="margin-left:4px;"></span>');
        $btn.prop('disabled', true).after(spinner);
        clearNotice();
        requestFn(function () {
            spinner.remove();
            $btn.prop('disabled', false);
        });
    }

    window.loadJobs = loadJobs;

    // Event: create a new job.
    $('#aipi-pro-create-job').on('click', function () {
        const $btn = $(this);
        ajaxWithSpinner($btn, function (done) {
            $.post(
                AIPI_PRO.ajaxUrl,
                {
                    action: 'aipi_create_job',
                    nonce: AIPI_PRO.nonce,
                },
                function (response) {
                    done();
                    if (response.success) {
                        showNotice('Draft created. Add photos or notes to continue.', 'success');
                        loadJobs();
                    } else {
                        showNotice(response.data.message || 'Error creating job', 'error');
                    }
                }
            ).fail(function () {
                done();
                showNotice('Could not start the draft. Please try again.', 'error');
            });
        });
    });

    // Delegate actions on job list.
    jobListEl.on('click', '.aipi-upload', function () {
        const $btn = $(this);
        const jobEl = $btn.closest('.aipi-job');
        const jobId = jobEl.data('job-id');
        const fileInput = jobEl.find('.aipi-photo-input')[0];
        const files = fileInput ? fileInput.files : null;
        if (!files || files.length === 0) {
            showNotice('Choose at least one photo first.', 'error');
            return;
        }
        ajaxWithSpinner($btn, function (done) {
            const formData = new FormData();
            formData.append('action', 'aipi_upload_photos');
            formData.append('nonce', AIPI_PRO.nonce);
            formData.append('job_id', jobId);
            for (let i = 0; i < files.length; i++) {
                formData.append('photos[]', files[i]);
            }
            $.ajax({
                url: AIPI_PRO.ajaxUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    done();
                    if (response.success) {
                        showNotice('Photos added to this draft.', 'success');
                        loadJobs();
                    } else {
                        showNotice(response.data.message || 'Error uploading photos', 'error');
                    }
                },
                error: function () {
                    done();
                    showNotice('Photos could not be uploaded. Please try again.', 'error');
                },
            });
        });
    });

    jobListEl.on('click', '.aipi-ready', function () {
        const $btn = $(this);
        const jobId = $btn.closest('.aipi-job').data('job-id');
        ajaxWithSpinner($btn, function (done) {
            $.post(
                AIPI_PRO.ajaxUrl,
                {
                    action: 'aipi_ready_for_generation',
                    nonce: AIPI_PRO.nonce,
                    job_id: jobId,
                },
                function (response) {
                    done();
                    if (response.success) {
                        showNotice('Draft is ready. Add notes and generate when you are ready.', 'success');
                        loadJobs();
                    } else {
                        showNotice(response.data.message || 'Error marking ready', 'error');
                    }
                }
            ).fail(function (xhr) {
                done();
                // Show a more informative error message when available.  Fall back to a generic
                // network error otherwise.  This avoids masking server‑side validation errors
                // as generic network issues, helping administrators understand why the
                // operation failed.
                let msg = 'Could not prepare this draft. Please try again.';
                try {
                    if (xhr && xhr.responseJSON) {
                        if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            msg = xhr.responseJSON.data.message;
                        } else if (xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }
                    } else if (xhr && xhr.responseText) {
                        msg = String(xhr.responseText);
                    }
                } catch (err) {
                    // ignore parse errors
                }
                showNotice(msg, 'error');
            });
        });
    });

    jobListEl.on('click', '.aipi-generate', function () {
        const $btn = $(this);
        const jobEl = $btn.closest('.aipi-job');
        const jobId = jobEl.data('job-id');
        const description = jobEl.find('.aipi-description').val();
        const useImagesEl = jobEl.find('.aipi-use-images')[0];
        const useImages = useImagesEl ? useImagesEl.checked : true;
        ajaxWithSpinner($btn, function (done) {
            $.post(
                AIPI_PRO.ajaxUrl,
                {
                    action: 'aipi_generate_listing',
                    nonce: AIPI_PRO.nonce,
                    job_id: jobId,
                    description: description,
                    use_images: useImages ? 1 : 0,
                },
                function (response) {
                    done();
                    if (response.success) {
                        showNotice('Draft prepared. Review it before creating the product.', 'success');
                        loadJobs();
                    } else {
                        showNotice(response.data.message || 'Error generating listing', 'error');
                    }
                }
            ).fail(function (xhr) {
                done();
                // Display detailed error message when provided by the server.  Fallback to a
                // generic network error otherwise.  This surfaces issues like invalid state
                // or missing configuration to the administrator.
                let msg = 'Could not prepare this draft. Please try again.';
                try {
                    if (xhr && xhr.responseJSON) {
                        if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            msg = xhr.responseJSON.data.message;
                        } else if (xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }
                    } else if (xhr && xhr.responseText) {
                        msg = String(xhr.responseText);
                    }
                } catch (err) {
                    // ignore parse errors
                }
                showNotice(msg, 'error');
            });
        });
    });

    jobListEl.on('click', '.aipi-create-product', function () {
        const $btn = $(this);
        const jobId = $btn.closest('.aipi-job').data('job-id');
        ajaxWithSpinner($btn, function (done) {
            $.post(
                AIPI_PRO.ajaxUrl,
                {
                    action: 'aipi_create_product',
                    nonce: AIPI_PRO.nonce,
                    job_id: jobId,
                },
                function (response) {
                    done();
                    if (response.success) {
                        showNotice('WooCommerce draft created.', 'success');
                        loadJobs();
                    } else {
                        showNotice(response.data.message || 'Error creating product', 'error');
                    }
                }
            ).fail(function () {
                done();
                showNotice('Could not create the WooCommerce draft. Please try again.', 'error');
            });
        });
    });

    jobListEl.on('click', '.aipi-retry', function () {
        const $btn = $(this);
        const jobId = $btn.closest('.aipi-job').data('job-id');
        ajaxWithSpinner($btn, function (done) {
            $.post(
                AIPI_PRO.ajaxUrl,
                {
                    action: 'aipi_ready_for_generation',
                    nonce: AIPI_PRO.nonce,
                    job_id: jobId,
                },
                function (response) {
                    done();
                    if (response.success) {
                        showNotice('Draft reset. You can prepare it again.', 'success');
                        loadJobs();
                    } else {
                        showNotice(response.data.message || 'Could not reset this draft.', 'error');
                    }
                }
            ).fail(function () {
                done();
                showNotice('Could not reset this draft. Please try again.', 'error');
            });
        });
    });

    jobListEl.on('click', '.aipi-delete', function () {
        const $btn = $(this);
        if (!confirm('Delete this draft? This cannot be undone.')) {
            return;
        }
        const jobId = $btn.closest('.aipi-job').data('job-id');
        ajaxWithSpinner($btn, function (done) {
            $.post(
                AIPI_PRO.ajaxUrl,
                {
                    action: 'aipi_delete_job',
                    nonce: AIPI_PRO.nonce,
                    job_id: jobId,
                },
                function (response) {
                    done();
                    if (response.success) {
                        showNotice('Draft deleted.', 'success');
                        loadJobs();
                    } else {
                        showNotice(response.data.message || 'Could not delete this draft.', 'error');
                    }
                }
            ).fail(function () {
                done();
                showNotice('Could not delete this draft. Please try again.', 'error');
            });
        });
    });


    jobListEl.on('click', '.aipi-page-prev', function () {
        if ($(this).is(':disabled') || currentPage <= 1) {
            return;
        }
        loadJobs(currentPage - 1);
    });

    jobListEl.on('click', '.aipi-page-next', function () {
        if ($(this).is(':disabled') || currentPage >= totalPages) {
            return;
        }
        loadJobs(currentPage + 1);
    });

    window.loadJobs = loadJobs;
    loadJobs();
});