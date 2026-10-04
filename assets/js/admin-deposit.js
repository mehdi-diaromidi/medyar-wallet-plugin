(function ($) {
    'use strict';

    var cfg = window.wwpAdminDeposit || {};
    var i18n = cfg.i18n || {};
    var STORAGE_KEY = 'wwp_deposit_queue_v1';
    var queue = [];
    var searchTimer = null;
    var searchXhr = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function digits(s) {
        return String(s || '').replace(/\D/g, '');
    }

    function formatAmount(n) {
        var v = Math.round(Number(n) || 0);
        try {
            return v.toLocaleString('fa-IR') + ' تومان';
        } catch (e) {
            return v.toLocaleString() + ' تومان';
        }
    }

    function showNotice(message, type) {
        var $n = $('#wwp-deposit-ajax-notice');
        if (!$n.length) {
            return;
        }
        $n
            .removeClass('notice-success notice-error notice-warning notice-info')
            .addClass('notice notice-' + (type || 'info'))
            .prop('hidden', false)
            .html('<p>' + esc(message) + '</p>');
    }

    function queueKey(item) {
        return String(item.user_id) + '|' + digits(item.card_number) + '|' + String(item.iban || '').toUpperCase();
    }

    function loadQueue() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            var parsed = raw ? JSON.parse(raw) : [];
            queue = Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            queue = [];
        }
    }

    function saveQueue() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(queue));
        } catch (e) { /* ignore */ }
    }

    function renderQueue() {
        var $body = $('#wwp-deposit-queue-body');
        var $empty = $('#wwp-deposit-queue-empty');
        var $wrap = $('#wwp-deposit-queue-wrap');
        var $count = $('#wwp-deposit-queue-count');
        var $run = $('#wwp-deposit-queue-run');
        var $clear = $('#wwp-deposit-queue-clear');
        var total = 0;

        $count.text(String(queue.length));
        $run.prop('disabled', queue.length === 0);
        $clear.prop('disabled', queue.length === 0);

        if (!queue.length) {
            $empty.prop('hidden', false);
            $wrap.prop('hidden', true);
            $body.empty();
            $('#wwp-deposit-queue-total').text(formatAmount(0));
            return;
        }

        $empty.prop('hidden', true);
        $wrap.prop('hidden', false);
        var html = '';
        queue.forEach(function (item, idx) {
            total += Number(item.amount) || 0;
            html +=
                '<tr data-queue-index="' + idx + '">' +
                '<td>' + (idx + 1) + '</td>' +
                '<td><strong>#' + esc(item.user_id) + '</strong><br>' + esc(item.display_name || '') +
                (item.user_email ? '<br><small>' + esc(item.user_email) + '</small>' : '') +
                '</td>' +
                '<td>' + esc(item.bank || i18n.dash || '—') +
                '<br><span dir="ltr">' + esc(item.card_number || i18n.dash || '—') + '</span></td>' +
                '<td dir="ltr">' + esc(item.iban || i18n.dash || '—') + '</td>' +
                '<td><input type="number" min="1" step="1" class="medyar-wallet-deposit-amount wwp-deposit-queue-amount" ' +
                'value="' + esc(item.amount) + '" dir="ltr" data-queue-index="' + idx + '"></td>' +
                '<td><button type="button" class="button-link-delete wwp-deposit-queue-remove" data-queue-index="' + idx + '">' +
                esc(i18n.remove || 'حذف') + '</button></td>' +
                '</tr>';
        });
        $body.html(html);
        $('#wwp-deposit-queue-total').text(formatAmount(total));
    }

    function addToQueue(item, opts) {
        opts = opts || {};
        var amount = Math.round(Number(item.amount) || 0);
        if (amount < 1) {
            showNotice(i18n.invalidAmount || 'مبلغ معتبر وارد کنید.', 'error');
            return false;
        }
        if (!item.user_id || (!item.card_number && !item.iban)) {
            showNotice(i18n.noCard || 'کارت یا شبا نامعتبر است.', 'error');
            return false;
        }

        var entry = {
            user_id: Number(item.user_id),
            display_name: item.display_name || '',
            user_email: item.user_email || '',
            bank: item.bank || '',
            card_number: digits(item.card_number),
            iban: String(item.iban || '').toUpperCase().replace(/\s+/g, ''),
            amount: amount
        };
        var key = queueKey(entry);
        var existing = -1;
        for (var i = 0; i < queue.length; i++) {
            if (queueKey(queue[i]) === key) {
                existing = i;
                break;
            }
        }
        if (existing >= 0) {
            queue[existing].amount = amount;
            saveQueue();
            renderQueue();
            if (!opts.silent) {
                showNotice(i18n.queueDuplicate || 'این کارت/شبا از قبل در صف است — مبلغ به‌روز شد.', 'warning');
            }
            return true;
        }

        if (queue.length >= (cfg.maxBatch || 50)) {
            showNotice('حداکثر ' + (cfg.maxBatch || 50) + ' مورد در صف مجاز است.', 'error');
            return false;
        }

        queue.push(entry);
        saveQueue();
        renderQueue();
        if (!opts.silent) {
            showNotice(i18n.queueAdded || 'به صف واریز اضافه شد.', 'success');
        }
        return true;
    }

    function rowPayload($tr, amount) {
        return {
            user_id: Number($tr.data('user-id')),
            display_name: String($tr.data('name') || ''),
            user_email: String($tr.data('email') || ''),
            bank: String($tr.data('bank') || ''),
            card_number: String($tr.data('card') || ''),
            iban: String($tr.data('iban') || ''),
            amount: amount
        };
    }

    function buildResultRowHtml(m) {
        var dash = i18n.dash || '—';
        var can = !!m.can_deposit;
        var actions;
        if (!can) {
            actions =
                '<p class="description">' + esc(i18n.noCard || '') + '</p>' +
                (m.insights_url
                    ? '<a class="button button-small" href="' + esc(m.insights_url) + '">' + esc(i18n.insights || 'پنل کاربر') + '</a>'
                    : '');
        } else {
            actions =
                '<div class="wwp-deposit-row-actions">' +
                '<input type="number" min="1" step="1" class="medyar-wallet-deposit-amount wwp-deposit-row-amount" placeholder="تومان" dir="ltr" aria-label="مبلغ واریز">' +
                '<button type="button" class="button button-primary wwp-deposit-btn-now">' + esc(i18n.depositNow || 'واریز') + '</button>' +
                '<button type="button" class="button wwp-deposit-btn-queue">' + esc(i18n.addToQueue || 'افزودن به صف') + '</button>' +
                '</div>';
        }

        return (
            '<tr class="wwp-deposit-result-row"' +
            ' data-user-id="' + esc(m.user_id) + '"' +
            ' data-card="' + esc(m.card_number || '') + '"' +
            ' data-iban="' + esc(m.iban || '') + '"' +
            ' data-bank="' + esc(m.bank || '') + '"' +
            ' data-owner="' + esc(m.owner || '') + '"' +
            ' data-name="' + esc(m.display_name || '') + '"' +
            ' data-email="' + esc(m.user_email || '') + '">' +
            '<td class="col-user"><strong>#' + esc(m.user_id) + '</strong>' +
            (m.display_name ? '<br>' + esc(m.display_name) : '') +
            (m.user_email ? '<br><small>' + esc(m.user_email) + '</small>' : '') +
            '</td>' +
            '<td dir="ltr">' + esc(m.balance_formatted || '0') + '</td>' +
            '<td>' + esc(m.bank || dash) + '</td>' +
            '<td dir="ltr">' + esc(m.card_number || dash) + '</td>' +
            '<td dir="ltr">' + esc(m.iban || dash) + '</td>' +
            '<td>' + esc(m.owner || dash) + '</td>' +
            '<td>' + actions + '</td>' +
            '</tr>'
        );
    }

    function renderResults(matches) {
        var $card = $('#wwp-deposit-results-card');
        var $empty = $('#wwp-deposit-results-empty');
        var $wrap = $('#wwp-deposit-results-wrap');
        var $body = $('#wwp-deposit-results-body');
        var count = Array.isArray(matches) ? matches.length : 0;

        $card.prop('hidden', false);
        $('#wwp-deposit-results-count').text('(' + count + ')');

        if (!count) {
            $empty.prop('hidden', false);
            $wrap.prop('hidden', true);
            $body.empty();
            return;
        }

        $empty.prop('hidden', true);
        $wrap.prop('hidden', false);
        $body.html(matches.map(buildResultRowHtml).join(''));
    }

    function getSearchParams() {
        return {
            user_q: $.trim($('#deposit_user_q').val() || ''),
            card: digits($('#deposit_search_card').val()),
            iban: String($('#deposit_search_iban').val() || '').toUpperCase().replace(/\s+/g, '')
        };
    }

    function updateUrl(params) {
        if (!window.history || !history.replaceState || !cfg.pageUrl) {
            return;
        }
        var url = new URL(cfg.pageUrl, window.location.origin);
        if (params.user_q) url.searchParams.set('user_q', params.user_q);
        if (params.card) url.searchParams.set('card', params.card);
        if (params.iban) url.searchParams.set('iban', params.iban);
        history.replaceState(null, '', url.toString());
    }

    function clearUrlSearch() {
        if (!window.history || !history.replaceState || !cfg.pageUrl) {
            return;
        }
        history.replaceState(null, '', cfg.pageUrl);
    }

    function setSearching(on) {
        $('#wwp-deposit-search-spinner').toggleClass('is-active', !!on);
        $('#wwp-deposit-search-btn').prop('disabled', !!on);
    }

    function doSearch(opts) {
        opts = opts || {};
        var params = getSearchParams();
        if (!params.user_q && !params.card && !params.iban) {
            if (!opts.silent) {
                showNotice(i18n.needQuery || 'حداقل یکی از فیلدهای جستجو را پر کنید.', 'error');
            }
            return;
        }

        // Avoid noisy/expensive queries while typing short fragments.
        if (opts.silent) {
            if (params.card && params.card.length < 4 && !params.iban && !params.user_q) {
                return;
            }
            if (params.user_q && params.user_q.length < 2 && !params.card && !params.iban) {
                return;
            }
            if (params.iban && params.iban.length < 4 && !params.card && !params.user_q) {
                return;
            }
        }

        if (searchXhr && searchXhr.readyState !== 4) {
            searchXhr.abort();
        }

        setSearching(true);
        searchXhr = $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'medyar_deposit_search',
                nonce: cfg.nonce,
                user_q: params.user_q,
                card: params.card,
                iban: params.iban
            }
        })
            .done(function (res) {
                if (!res || !res.success) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : (i18n.searchError || 'خطا');
                    showNotice(msg, 'error');
                    return;
                }
                updateUrl(params);
                renderResults(res.data.matches || []);
            })
            .fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                showNotice(i18n.searchError || 'خطا در جستجو.', 'error');
            })
            .always(function () {
                setSearching(false);
            });
    }

    function depositNowFromRow($tr) {
        var $amount = $tr.find('.wwp-deposit-row-amount');
        var amount = Math.round(Number($amount.val()) || 0);
        if (amount < 1) {
            showNotice(i18n.invalidAmount || 'مبلغ معتبر وارد کنید.', 'error');
            $amount.trigger('focus');
            return;
        }

        var $form = $('<form method="post" action="' + esc(cfg.adminPostUrl) + '" style="display:none"></form>');
        $form.append($('<input>', { type: 'hidden', name: 'action', value: 'medyar_manual_deposit' }));
        $form.append($('<input>', { type: 'hidden', name: 'medyar_manual_deposit_nonce', value: cfg.depositNonce }));
        $form.append($('<input>', { type: 'hidden', name: 'user_id', value: $tr.data('user-id') }));
        $form.append($('<input>', { type: 'hidden', name: 'card_number', value: $tr.data('card') || '' }));
        $form.append($('<input>', { type: 'hidden', name: 'iban', value: $tr.data('iban') || '' }));
        $form.append($('<input>', { type: 'hidden', name: 'amount', value: amount }));
        $form.append($('<input>', { type: 'hidden', name: 'search_card', value: digits($('#deposit_search_card').val()) }));
        $form.append($('<input>', { type: 'hidden', name: 'search_iban', value: String($('#deposit_search_iban').val() || '') }));
        $form.append($('<input>', { type: 'hidden', name: 'search_user_q', value: $('#deposit_user_q').val() || '' }));
        $('body').append($form);
        $form.trigger('submit');
    }

    function runBatch() {
        if (!queue.length) {
            showNotice(i18n.queueEmpty || 'صف واریز خالی است.', 'error');
            return;
        }
        if (!window.confirm(i18n.confirmBatch || 'واریز همه موارد صف انجام شود؟')) {
            return;
        }

        var $status = $('#wwp-deposit-batch-status');
        var $run = $('#wwp-deposit-queue-run');
        $run.prop('disabled', true);
        $status
            .prop('hidden', false)
            .removeClass('is-ok is-bad is-warn')
            .addClass('is-warn')
            .text(i18n.batchRunning || 'در حال واریز…');

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'medyar_manual_deposit_batch',
                nonce: cfg.nonce,
                items: JSON.stringify(queue)
            }
        })
            .done(function (res) {
                if (!res || !res.success) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : (i18n.batchError || 'خطا');
                    $status.removeClass('is-warn').addClass('is-bad').text(msg);
                    $run.prop('disabled', false);
                    return;
                }

                var okCount = Number(res.data.ok_count || 0);
                var failCount = Number(res.data.fail_count || 0);
                var failed = res.data.failed || [];
                var logToken = res.data.log_token || '';

                if (failCount === 0) {
                    queue = [];
                    saveQueue();
                    renderQueue();
                } else {
                    var failedIndexes = {};
                    failed.forEach(function (f) {
                        failedIndexes[Number(f.index)] = true;
                    });
                    queue = queue.filter(function (_item, idx) {
                        return !!failedIndexes[idx];
                    });
                    saveQueue();
                    renderQueue();
                }

                var redirect = cfg.pageUrl || window.location.href.split('?')[0] + '?page=medyar-wallet-deposit';
                var url = new URL(redirect, window.location.origin);
                if (logToken) {
                    url.searchParams.set('batch_log', logToken);
                } else {
                    url.searchParams.set('batch_ok', String(okCount));
                    url.searchParams.set('batch_fail', String(failCount));
                }
                window.location.href = url.toString();
            })
            .fail(function () {
                $status.removeClass('is-warn').addClass('is-bad').text(i18n.batchError || 'خطا در اجرای واریز گروهی.');
                $run.prop('disabled', false);
            });
    }

    function quickAdd() {
        var card = digits($('#wwp-deposit-quick-card').val());
        var amount = Math.round(Number($('#wwp-deposit-quick-amount').val()) || 0);
        if (card.length < 16) {
            showNotice('شماره کارت معتبر وارد کنید.', 'error');
            return;
        }
        if (amount < 1) {
            showNotice(i18n.invalidAmount || 'مبلغ معتبر وارد کنید.', 'error');
            return;
        }

        var $spin = $('#wwp-deposit-quick-spinner').addClass('is-active');
        $('#wwp-deposit-quick-add-btn').prop('disabled', true);

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'medyar_deposit_search',
                nonce: cfg.nonce,
                card: card
            }
        })
            .done(function (res) {
                if (!res || !res.success) {
                    showNotice((res && res.data && res.data.message) || i18n.searchError, 'error');
                    return;
                }
                var matches = (res.data.matches || []).filter(function (m) {
                    return m.can_deposit && digits(m.card_number) === card;
                });
                if (!matches.length) {
                    showNotice(i18n.noResults || 'کاربری یافت نشد.', 'error');
                    return;
                }
                var m = matches[0];
                addToQueue({
                    user_id: m.user_id,
                    display_name: m.display_name,
                    user_email: m.user_email,
                    bank: m.bank,
                    card_number: m.card_number,
                    iban: m.iban,
                    amount: amount
                });
                $('#wwp-deposit-quick-card').val('');
                $('#wwp-deposit-quick-amount').val('');
                $('#wwp-deposit-quick-card').trigger('focus');
            })
            .fail(function () {
                showNotice(i18n.searchError || 'خطا در جستجو.', 'error');
            })
            .always(function () {
                $spin.removeClass('is-active');
                $('#wwp-deposit-quick-add-btn').prop('disabled', false);
            });
    }

    $(function () {
        if (!$('#wwp-deposit-page').length) {
            return;
        }

        loadQueue();
        renderQueue();

        $('#wwp-deposit-search-form').on('submit', function (e) {
            e.preventDefault();
            doSearch();
        });

        $('#wwp-deposit-search-reset').on('click', function () {
            $('#deposit_user_q, #deposit_search_card, #deposit_search_iban').val('');
            $('#wwp-deposit-results-card').prop('hidden', true);
            $('#wwp-deposit-results-body').empty();
            clearUrlSearch();
        });

        // Debounced live search when typing card (exact-ish) or after pause on other fields
        $('#deposit_user_q, #deposit_search_card, #deposit_search_iban').on('input', function () {
            clearTimeout(searchTimer);
            var params = getSearchParams();
            var delay = params.card.length >= 4 || params.iban.length >= 4 ? 350 : 650;
            if (!params.user_q && !params.card && !params.iban) {
                return;
            }
            searchTimer = setTimeout(function () {
                doSearch({ silent: true });
            }, delay);
        });

        $('#wwp-deposit-results-body')
            .on('click', '.wwp-deposit-btn-queue', function () {
                var $tr = $(this).closest('tr');
                var amount = Math.round(Number($tr.find('.wwp-deposit-row-amount').val()) || 0);
                addToQueue(rowPayload($tr, amount));
            })
            .on('click', '.wwp-deposit-btn-now', function () {
                depositNowFromRow($(this).closest('tr'));
            })
            .on('keydown', '.wwp-deposit-row-amount', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $(this).closest('tr').find('.wwp-deposit-btn-now').trigger('click');
                }
            });

        $('#wwp-deposit-queue-body')
            .on('click', '.wwp-deposit-queue-remove', function () {
                var idx = Number($(this).data('queue-index'));
                if (idx >= 0 && idx < queue.length) {
                    queue.splice(idx, 1);
                    saveQueue();
                    renderQueue();
                }
            })
            .on('change', '.wwp-deposit-queue-amount', function () {
                var idx = Number($(this).data('queue-index'));
                var amount = Math.round(Number($(this).val()) || 0);
                if (idx >= 0 && idx < queue.length) {
                    if (amount < 1) {
                        $(this).val(queue[idx].amount);
                        showNotice(i18n.invalidAmount || 'مبلغ معتبر وارد کنید.', 'error');
                        return;
                    }
                    queue[idx].amount = amount;
                    saveQueue();
                    renderQueue();
                }
            });

        $('#wwp-deposit-queue-clear').on('click', function () {
            if (!queue.length) {
                return;
            }
            if (!window.confirm('صف واریز خالی شود؟')) {
                return;
            }
            queue = [];
            saveQueue();
            renderQueue();
        });

        $('#wwp-deposit-queue-run').on('click', runBatch);
        $('#wwp-deposit-quick-add-btn').on('click', quickAdd);
        $('#wwp-deposit-quick-card, #wwp-deposit-quick-amount').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                quickAdd();
            }
        });
    });
})(jQuery);
