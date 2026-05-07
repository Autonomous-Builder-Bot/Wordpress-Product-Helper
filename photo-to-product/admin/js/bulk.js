/* global jQuery, AIPI_PRO */

jQuery(function ($) {
    const bulkRoot = $('#aipi-bulk-create');
    if (!bulkRoot.length || typeof AIPI_PRO === 'undefined') {
        return;
    }

    const fileInput = $('#aipi-bulk-files');
    const groupsWrap = $('#aipi-bulk-groups');
    const summaryWrap = $('#aipi-bulk-summary');
    const progressWrap = $('#aipi-bulk-progress');
    const resultsWrap = $('#aipi-bulk-results');
    const createGroupBtn = $('#aipi-bulk-add-group');
    const processBtn = $('#aipi-bulk-process');
    const resetBtn = $('#aipi-bulk-reset');
    const openBtn = $('#aipi-open-bulk-create');
    const panel = $('#aipi-bulk-panel');

    let filesStore = [];
    let groups = [];
    let nextGroupId = 1;
    let isProcessing = false;

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setProgress(html, type) {
        const cls = type ? ' notice notice-' + type : '';
        progressWrap.html('<div class="aipi-bulk-status' + cls + '">' + html + '</div>');
    }

    function renderResultSummary(items, failures) {
        let html = '<div class="aipi-bulk-result-block"><strong>Batch Results</strong>';
        const succeeded = items.length - failures.length;
        html += '<p>' + succeeded + ' product draft' + (succeeded === 1 ? '' : 's') + ' created.';
        if (failures.length) {
            html += ' ' + failures.length + ' group' + (failures.length === 1 ? '' : 's') + ' need attention.';
        }
        html += '</p>';
        if (failures.length) {
            html += '<ul class="aipi-simple-list">';
            failures.forEach(function (failure) {
                html += '<li><strong>' + escHtml(failure.group) + ':</strong> ' + escHtml(failure.message || 'Could not create this draft.') + '</li>';
            });
            html += '</ul>';
        }
        html += '<p class="description">Review Recent Drafts below. Any group that needs attention can be recreated after you adjust the photos or notes.</p>';
        html += '</div>';
        return html;
    }

    function ensureDefaultGroup() {
        if (groups.length === 0) {
            addGroup();
        }
    }

    function addGroup() {
        const id = 'group-' + nextGroupId++;
        groups.push({ id: id, name: 'Product ' + (groups.length + 1), notes: '' });
        render();
        return id;
    }

    function resetBulk() {
        filesStore = [];
        groups = [];
        nextGroupId = 1;
        isProcessing = false;
        fileInput.val('');
        progressWrap.empty();
        resultsWrap.empty();
        ensureDefaultGroup();
        render();
    }

    function updateFilesFromInput(fileList) {
        const previousAssignments = {};
        filesStore.forEach(function (file) {
            previousAssignments[file.key] = file.groupId || '';
        });

        filesStore = Array.from(fileList || []).map(function (file, index) {
            const key = [file.name, file.size, file.lastModified, index].join('|');
            return {
                key: key,
                file: file,
                groupId: previousAssignments[key] || '',
                preview: URL.createObjectURL(file)
            };
        });
        ensureDefaultGroup();
        if (groups[0] && filesStore.length && !filesStore.some(function (item) { return item.groupId; })) {
            filesStore.forEach(function (item) {
                item.groupId = groups[0].id;
            });
        }
        render();
    }

    function getFilesForGroup(groupId) {
        return filesStore.filter(function (item) { return item.groupId === groupId; });
    }

    function renderSummary() {
        const grouped = groups.map(function (group) {
            return {
                name: group.name,
                count: getFilesForGroup(group.id).length,
                notes: group.notes
            };
        }).filter(function (group) { return group.count > 0 || group.notes.trim() !== ''; });

        if (!filesStore.length) {
            summaryWrap.html('<p class="description">Upload photos to start a batch.</p>');
            return;
        }

        const assignedCount = filesStore.filter(function (item) { return item.groupId; }).length;
        const unassignedCount = filesStore.length - assignedCount;
        let html = '<div class="aipi-bulk-summary-meta"><span>' + escHtml(String(filesStore.length)) + ' photos uploaded</span><span>' + escHtml(String(assignedCount)) + ' assigned</span><span>' + escHtml(String(grouped.length)) + ' product groups</span>' + (unassignedCount > 0 ? '<span class="aipi-summary-warning">' + escHtml(String(unassignedCount)) + ' still need a group</span>' : '') + '</div>';
        if (grouped.length) {
            html += '<ul class="aipi-bulk-summary-list">';
            grouped.forEach(function (group) {
                html += '<li><strong>' + escHtml(group.name) + '</strong> · ' + escHtml(String(group.count)) + ' photo' + (group.count === 1 ? '' : 's');
                if (group.notes.trim()) {
                    html += '<div class="aipi-bulk-summary-notes">' + escHtml(group.notes.trim()) + '</div>';
                }
                html += '</li>';
            });
            html += '</ul>';
        }
        summaryWrap.html(html);
    }

    function renderGroups() {
        if (!groups.length) {
            ensureDefaultGroup();
        }
        let html = '';
        groups.forEach(function (group, index) {
            const groupFiles = getFilesForGroup(group.id);
            html += '<div class="aipi-bulk-group-card" data-group-id="' + escHtml(group.id) + '">';
            html += '<div class="aipi-bulk-group-head"><input type="text" class="regular-text aipi-bulk-group-name" value="' + escHtml(group.name) + '" aria-label="Group name" />';
            if (groups.length > 1) {
                html += '<button type="button" class="button-link-delete aipi-bulk-remove-group">Remove</button>';
            }
            html += '</div>';
            html += '<p class="aipi-bulk-group-count">' + escHtml(String(groupFiles.length)) + ' photo' + (groupFiles.length === 1 ? '' : 's') + '</p>';
            html += '<textarea class="large-text aipi-bulk-group-notes" rows="3" placeholder="Short notes for this product">' + escHtml(group.notes) + '</textarea>';
            if (groupFiles.length) {
                html += '<div class="aipi-bulk-thumb-row">';
                groupFiles.forEach(function (item) {
                    html += '<figure class="aipi-bulk-thumb"><img src="' + escHtml(item.preview) + '" alt="" /><figcaption>' + escHtml(item.file.name) + '</figcaption></figure>';
                });
                html += '</div>';
            }
            html += '</div>';
        });
        groupsWrap.html(html);
    }

    function renderFileTable() {
        if (!filesStore.length) {
            return '<div class="aipi-bulk-empty"><strong>No photos selected yet.</strong><span>Choose photos, then assign each one to a product group.</span></div>';
        }
        let html = '<table class="widefat striped aipi-bulk-file-table"><thead><tr><th>Photo</th><th>Assign to Product</th></tr></thead><tbody>';
        filesStore.forEach(function (item) {
            html += '<tr data-file-key="' + escHtml(item.key) + '">';
            html += '<td><div class="aipi-bulk-file-meta"><img src="' + escHtml(item.preview) + '" alt="" /><div><strong>' + escHtml(item.file.name) + '</strong><span>' + escHtml(Math.max(1, Math.round(item.file.size / 1024))) + ' KB</span></div></div></td>';
            html += '<td><select class="aipi-bulk-file-group">';
            html += '<option value="">Choose product group</option>';
            groups.forEach(function (group) {
                const selected = item.groupId === group.id ? ' selected="selected"' : '';
                html += '<option value="' + escHtml(group.id) + '"' + selected + '>' + escHtml(group.name) + '</option>';
            });
            html += '</select></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function getUnassignedFiles() {
        return filesStore.filter(function (item) { return !item.groupId; });
    }

    function validateBatch() {
        const problems = [];
        if (!filesStore.length) {
            problems.push('Upload photos to start a batch.');
        }
        if (getUnassignedFiles().length) {
            problems.push('Assign every photo to a product group before you create drafts.');
        }
        const processable = getProcessableGroups();
        if (!processable.length) {
            problems.push('Create at least one product group with photos.');
        }
        return problems;
    }

    function render() {
        groupsWrap.prev('.aipi-bulk-file-list').remove();
        groupsWrap.before('<div class="aipi-bulk-file-list">' + renderFileTable() + '</div>');
        renderGroups();
        renderSummary();
        const hasProcessableGroups = groups.some(function (group) {
            return getFilesForGroup(group.id).length > 0;
        });
        const problems = validateBatch();
        processBtn.prop('disabled', isProcessing || !hasProcessableGroups || problems.length > 0);
        resetBtn.prop('disabled', isProcessing);
        createGroupBtn.prop('disabled', isProcessing);
        fileInput.prop('disabled', isProcessing);
    }

    function createJob() {
        return $.post(AIPI_PRO.ajaxUrl, { action: 'aipi_create_job', nonce: AIPI_PRO.nonce });
    }

    function uploadFiles(jobId, files) {
        const formData = new FormData();
        formData.append('action', 'aipi_upload_photos');
        formData.append('nonce', AIPI_PRO.nonce);
        formData.append('job_id', jobId);
        files.forEach(function (item) {
            formData.append('photos[]', item.file);
        });
        return $.ajax({
            url: AIPI_PRO.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false
        });
    }

    function readyJob(jobId) {
        return $.post(AIPI_PRO.ajaxUrl, { action: 'aipi_ready_for_generation', nonce: AIPI_PRO.nonce, job_id: jobId });
    }

    function generateJob(jobId, notes, useImages) {
        return $.post(AIPI_PRO.ajaxUrl, {
            action: 'aipi_generate_listing',
            nonce: AIPI_PRO.nonce,
            job_id: jobId,
            description: notes || '',
            use_images: useImages ? '1' : '0'
        });
    }

    function createProduct(jobId) {
        return $.post(AIPI_PRO.ajaxUrl, { action: 'aipi_create_product', nonce: AIPI_PRO.nonce, job_id: jobId });
    }

    function getProcessableGroups() {
        return groups.map(function (group) {
            return {
                id: group.id,
                name: group.name.trim() || 'Product',
                notes: group.notes,
                files: getFilesForGroup(group.id)
            };
        }).filter(function (group) { return group.files.length > 0; });
    }

    function renderResults(successes, failures) {
        if (!successes.length && !failures.length) {
            resultsWrap.empty();
            return;
        }
        let html = '<div class="aipi-card aipi-subcard"><span class="aipi-eyebrow">Batch Results</span><h3>Latest batch</h3>';
        if (successes.length) {
            html += '<div class="aipi-bulk-result-block"><strong>Created drafts</strong><ul class="aipi-simple-list">';
            successes.forEach(function (item) {
                html += '<li>' + escHtml(item.group) + (item.productId ? ' · Draft #' + escHtml(String(item.productId)) : '') + '</li>';
            });
            html += '</ul></div>';
        }
        if (failures.length) {
            html += '<div class="aipi-bulk-result-block"><strong>Needs attention</strong><ul class="aipi-simple-list">';
            failures.forEach(function (item) {
                html += '<li><strong>' + escHtml(item.group) + ':</strong> ' + escHtml(item.message) + '</li>';
            });
            html += '</ul><p class="description">Fix the affected group and run another batch when you are ready.</p></div>';
        }
        html += '</div>';
        resultsWrap.html(html);
    }

    function processBatch() {
        const items = getProcessableGroups();
        const problems = validateBatch();
        if (problems.length) {
            setProgress(problems[0], 'warning');
            return;
        }
        isProcessing = true;
        render();
        let completed = 0;
        const failures = [];
        const successes = [];
        setProgress('Starting your batch…');

        function next(index) {
            if (index >= items.length) {
                isProcessing = false;
                render();
                renderResults(successes, failures);
                if (failures.length) {
                    setProgress('Created ' + completed + ' of ' + items.length + ' draft products. Review the groups that still need attention below.', 'warning');
                } else {
                    setProgress('Finished all ' + completed + ' product groups.' + renderResultSummary(items, failures), 'success');
                }
                if (typeof window.jQuery === 'function') {
                    $(document).trigger('aipi:bulk-finished');
                }
                return;
            }
            const item = items[index];
            setProgress('Creating draft for ' + escHtml(item.name) + ' (' + (index + 1) + ' of ' + items.length + ')…');
            createJob()
                .then(function (response) {
                    if (!response.success || !response.data || !response.data.jobId) {
                        return $.Deferred().reject(response && response.data && response.data.message ? response.data.message : 'Could not create a draft.');
                    }
                    const jobId = response.data.jobId;
                    return uploadFiles(jobId, item.files)
                        .then(function () { return readyJob(jobId); })
                        .then(function () { return generateJob(jobId, item.notes, item.files.length > 0); })
                        .then(function (genResponse) {
                            if (!genResponse.success) {
                                return $.Deferred().reject(genResponse.data && genResponse.data.message ? genResponse.data.message : 'Could not generate this draft.');
                            }
                            return createProduct(jobId);
                        })
                        .then(function (productResponse) {
                            if (!productResponse.success) {
                                return $.Deferred().reject(productResponse.data && productResponse.data.message ? productResponse.data.message : 'Could not create the WooCommerce draft.');
                            }
                            completed += 1;
                            successes.push({ group: item.name, productId: productResponse.data && productResponse.data.productId ? productResponse.data.productId : '' });
                            return true;
                        });
                })
                .fail(function (message) {
                    failures.push({ group: item.name, message: message || 'Unknown error' });
                })
                .always(function () {
                    next(index + 1);
                });
        }

        next(0);
    }

    fileInput.on('change', function () {
        updateFilesFromInput(this.files);
    });

    groupsWrap.on('change', '.aipi-bulk-group-name', function () {
        const groupId = $(this).closest('.aipi-bulk-group-card').data('group-id');
        const group = groups.find(function (item) { return item.id === groupId; });
        if (group) {
            group.name = $(this).val();
            render();
        }
    });

    groupsWrap.on('input', '.aipi-bulk-group-notes', function () {
        const groupId = $(this).closest('.aipi-bulk-group-card').data('group-id');
        const group = groups.find(function (item) { return item.id === groupId; });
        if (group) {
            group.notes = $(this).val();
            renderSummary();
        }
    });

    groupsWrap.on('click', '.aipi-bulk-remove-group', function () {
        const groupId = $(this).closest('.aipi-bulk-group-card').data('group-id');
        if (groups.length <= 1) {
            return;
        }
        filesStore.forEach(function (item) {
            if (item.groupId === groupId) {
                item.groupId = '';
            }
        });
        groups = groups.filter(function (group) { return group.id !== groupId; });
        render();
    });

    bulkRoot.on('change', '.aipi-bulk-file-group', function () {
        const row = $(this).closest('tr');
        const fileKey = row.data('file-key');
        const fileItem = filesStore.find(function (item) { return item.key === fileKey; });
        if (fileItem) {
            fileItem.groupId = $(this).val();
            render();
        }
    });

    createGroupBtn.on('click', function () {
        addGroup();
    });

    processBtn.on('click', function () {
        processBatch();
    });

    resetBtn.on('click', function () {
        resetBulk();
    });

    openBtn.on('click', function () {
        panel.attr('open', 'open');
        fileInput.trigger('focus');
    });

    $(document).on('aipi:bulk-finished', function () {
        if (typeof window.loadJobs === 'function') {
            window.loadJobs();
        }
    });

    ensureDefaultGroup();
    render();
});
