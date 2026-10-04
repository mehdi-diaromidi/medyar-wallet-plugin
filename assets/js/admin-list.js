(function ($) {
  'use strict';

  if (typeof wwpAdminList === 'undefined') {
    return;
  }

  function storageKey($bar) {
    return $bar.data('storage-key') || ('mtw_admin_list_state:' + ($bar.data('page') || 'default'));
  }

  function readForm($bar) {
    var data = {};
    $bar.find('.wwp-admin-list-form').serializeArray().forEach(function (item) {
      data[item.name] = item.value;
    });
    $bar.find('.wwp-admin-list-form input[type=checkbox]').each(function () {
      data[this.name] = this.checked ? '1' : '';
    });
    return data;
  }

  /** Normalize filter date for display in Jalali persianDatepicker (YYYY/MM/DD). */
  function toJalaliDisplayValue(raw) {
    var s = String(raw == null ? '' : raw).trim();
    if (!s) return '';
    s = s.replace(/[۰-۹]/g, function (d) {
      return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    }).replace('T', ' ').replace(/-/g, '/');
    var m = s.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})/);
    if (!m) return s;
    var y = parseInt(m[1], 10);
    var mo = parseInt(m[2], 10);
    var d = parseInt(m[3], 10);
    // Old saved Gregorian → convert to Jalali for the input
    if (y >= 1700 && typeof jDateFunctions === 'function') {
      try {
        var jdf = new jDateFunctions();
        var pd = jdf.gregorian_to_jalali(new Date(y, mo - 1, d));
        return pd.toString('YYYY/0M/0D');
      } catch (e) { /* fall through */ }
    }
    return (
      String(y) +
      '/' +
      (mo < 10 ? '0' + mo : String(mo)) +
      '/' +
      (d < 10 ? '0' + d : String(d))
    );
  }

  function writeForm($bar, state) {
    if (!state || typeof state !== 'object') return;
    Object.keys(state).forEach(function (key) {
      var $el = $bar.find('[name="' + key + '"]');
      if (!$el.length) return;
      if ($el.is(':checkbox')) {
        $el.prop('checked', !!state[key] && state[key] !== '0' && state[key] !== '');
      } else if ($el.hasClass('wwp-admin-datepicker')) {
        $el.val(toJalaliDisplayValue(state[key]));
      } else {
        $el.val(state[key]);
      }
    });
  }

  /** Clear a Jalali datepicker field (readonly inputs can't be erased by typing). */
  function syncDatepickerClearBtn($el) {
    var $wrap = $($el).closest('.wwp-datepicker-wrap');
    var $btn = $wrap.find('.wwp-datepicker-clear');
    if (!$btn.length) return;
    var hasVal = String($($el).val() || '').trim() !== '';
    $btn.prop('hidden', !hasVal);
  }

  function clearDatepicker($el) {
    var $el = $($el);
    $el.val('');
    $el.removeAttr('data-jDate').removeAttr('data-gDate');
    $el.removeData('jDate').removeData('gDate');
    // Hide open calendar if any
    var pdpId = $el.attr('pdp-id');
    if (pdpId) {
      $('#' + pdpId).hide();
    }
    syncDatepickerClearBtn($el);
    $el.trigger('change');
  }

  /** Persian WooCommerce product/order style: Jalali value in the input (not Gregorian). */
  function initJalaliDatepickers($ctx) {
    if (typeof $.fn.persianDatepicker !== 'function') return;
    $ctx.find('input.wwp-admin-datepicker').each(function () {
      var $el = $(this);
      if ($el.data('pdp-inited')) return;
      $el.data('pdp-inited', 1);
      if ($el.attr('type') === 'datetime-local' || $el.attr('type') === 'date') {
        $el.attr('type', 'text');
      }
      $el.removeAttr('placeholder');
      var val = toJalaliDisplayValue($el.val());
      if (val) $el.val(val);
      var args = {
        formatDate: 'YYYY/0M/0D',
        showGregorianDate: false,
        persianNumbers: true,
        onSelect: function () {
          syncDatepickerClearBtn($el);
        }
      };
      if (val) {
        args.selectedBefore = 1;
        args.selectedDate = val;
      }
      $el.persianDatepicker(args);
      syncDatepickerClearBtn($el);

      // Backspace / Delete / Escape clears even when readonly
      $el.on('keydown.wwpClearDate', function (e) {
        if (e.key === 'Backspace' || e.key === 'Delete' || e.key === 'Escape' ||
            e.keyCode === 8 || e.keyCode === 46 || e.keyCode === 27) {
          e.preventDefault();
          clearDatepicker($el);
        }
      });
      $el.on('change.wwpClearDate input.wwpClearDate', function () {
        syncDatepickerClearBtn($el);
      });
    });
  }

  function saveState($bar) {
    try {
      localStorage.setItem(storageKey($bar), JSON.stringify(readForm($bar)));
    } catch (e) {}
  }

  function loadState($bar) {
    try {
      var raw = localStorage.getItem(storageKey($bar));
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function syncUrl($bar, data) {
    if (!window.history || !window.history.replaceState) return;
    var url = new URL(window.location.href);
    var keep = ['page', 'user_id'];
    var next = new URLSearchParams();
    keep.forEach(function (k) {
      if (url.searchParams.has(k)) next.set(k, url.searchParams.get(k));
    });
    Object.keys(data).forEach(function (k) {
      if (data[k] === undefined || data[k] === null || data[k] === '') return;
      if (k === 'per_page' && String(data[k]) === '50') return;
      next.set(k, data[k]);
    });
    var qs = next.toString();
    window.history.replaceState({}, '', url.pathname + (qs ? '?' + qs : '') + url.hash);
  }

  /**
   * Scope that contains BOTH the filter bar and the table/pager.
   * Must NOT treat the filter .wallet-card itself as scope (siblings live in wrap).
   */
  function listScope($bar) {
    var $scope = $bar.closest('.wwp-details-inner, .wwp-user-panel-details, [data-wwp-list-scope]');
    if (!$scope.length) {
      $scope = $bar.closest('.wallet-admin-wrap, .wrap');
    }
    return $scope;
  }

  function targetBody($bar) {
    var sel = $bar.data('target-body');
    if (sel) return $(sel);
    return listScope($bar).find('[data-wwp-list-body]').first();
  }

  function targetPager($bar) {
    var sel = $bar.data('target-pager');
    if (sel) return $(sel);
    return listScope($bar).find('[data-wwp-list-pager]').first();
  }

  function targetMeta($bar) {
    return $bar.find('[data-wwp-list-meta]');
  }

  function fetchList($bar, opts) {
    opts = opts || {};
    var entity = $bar.data('entity');
    if (!entity) return;

    var data = readForm($bar);
    if (opts.page) data.paged = opts.page;
    if (opts.orderby) {
      data.orderby = opts.orderby;
      if (opts.order) data.order = opts.order;
      $bar.find('[name=orderby]').val(data.orderby);
      $bar.find('[name=order]').val(data.order);
    }

    data.action = 'mtw_admin_list_' + entity;
    data.nonce = wwpAdminList.nonce;
    data.entity = entity;

    var $body = targetBody($bar);
    var $pager = targetPager($bar);
    var $meta = targetMeta($bar);
    $bar.addClass('is-loading');
    $meta.text(wwpAdminList.i18n.loading);

    $.post(wwpAdminList.ajaxUrl, data)
      .done(function (res) {
        if (!res || !res.success || !res.data) {
          $meta.text(wwpAdminList.i18n.error);
          return;
        }
        var d = res.data;
        if ($body.length) {
          $body.html(d.rows_html || '');
        }
        if ($pager.length) {
          $pager.html(d.pagination_html || '');
        }
        if (d.meta) {
          $meta.text((d.meta.total != null ? numberFa(d.meta.total) + ' مورد' : ''));
          $bar.find('[name=paged]').val(d.meta.page || 1);
          updateSortIndicators($bar, d.meta.orderby, d.meta.order);
        }
        if (d.tx_js_data && window.wwpTxData) {
          Object.keys(d.tx_js_data).forEach(function (id) {
            window.wwpTxData[id] = d.tx_js_data[id];
          });
        } else if (d.tx_js_data && typeof window.wwpMergeTxData === 'function') {
          window.wwpMergeTxData(d.tx_js_data);
        } else if (d.tx_js_data) {
          window.wwpTxData = window.wwpTxData || {};
          Object.keys(d.tx_js_data).forEach(function (id) {
            window.wwpTxData[id] = d.tx_js_data[id];
          });
        }
        saveState($bar);
        syncUrl($bar, readForm($bar));
        $(document).trigger('wwp-admin-list-updated', [entity, d]);
      })
      .fail(function () {
        $meta.text(wwpAdminList.i18n.error);
      })
      .always(function () {
        $bar.removeClass('is-loading');
      });
  }

  function numberFa(n) {
    try {
      return Number(n).toLocaleString('fa-IR');
    } catch (e) {
      return String(n);
    }
  }

  function updateSortIndicators($bar, orderby, order) {
    var $scope = listScope($bar);
    $scope.find('th.wwp-sortable').removeClass('is-asc is-desc');
    if (!orderby) return;
    var $th = $scope.find('th.wwp-sortable[data-orderby="' + orderby + '"]');
    $th.addClass(order === 'ASC' ? 'is-asc' : 'is-desc');
  }

  function selectedIds($scope) {
    var ids = [];
    $scope.find('.wwp-row-check:checked').each(function () {
      ids.push($(this).val());
    });
    return ids;
  }

  function exportCsv($bar) {
    var action = $bar.find('.wwp-admin-list-export').data('export-action');
    if (!action) return;
    var data = readForm($bar);
    var $form = $('<form method="post" action="' + wwpAdminList.adminPostUrl + '"></form>');
    $form.append($('<input type="hidden" name="action">').val(action));
    $form.append($('<input type="hidden" name="nonce">').val(wwpAdminList.nonce));
    Object.keys(data).forEach(function (k) {
      $form.append($('<input type="hidden">').attr('name', k).val(data[k]));
    });
    $('body').append($form);
    $form.trigger('submit');
    $form.remove();
  }

  function initBar($bar) {
    if ($bar.data('wwp-inited')) return;
    $bar.data('wwp-inited', 1);

    var fromUrl = {};
    try {
      var sp = new URL(window.location.href).searchParams;
      sp.forEach(function (v, k) {
        if (k === 'page') return;
        fromUrl[k] = v;
      });
    } catch (e) {}

    var saved = loadState($bar);
    // Restore UI state only — do NOT auto-run AJAX until user clicks Apply / sort / pager.
    if (Object.keys(fromUrl).length) {
      writeForm($bar, fromUrl);
    } else if (saved) {
      writeForm($bar, saved);
    }

    initJalaliDatepickers($bar);

    $bar.on('click', '.wwp-datepicker-clear', function (e) {
      e.preventDefault();
      e.stopPropagation();
      clearDatepicker($(this).siblings('input.wwp-admin-datepicker').first());
    });

    $bar.on('click', '.wwp-admin-list-apply', function (e) {
      e.preventDefault();
      $bar.find('[name=paged]').val(1);
      fetchList($bar);
    });

    $bar.on('click', '.wwp-admin-list-reset', function (e) {
      e.preventDefault();
      var $form = $bar.find('.wwp-admin-list-form');
      $form[0].reset();
      $bar.find('input.wwp-admin-datepicker').each(function () {
        clearDatepicker(this);
      });
      $bar.find('[name=paged]').val(1);
      $bar.find('[name=orderby]').val($bar.data('default-orderby') || 'created_at');
      $bar.find('[name=order]').val($bar.data('default-order') || 'DESC');
      try {
        localStorage.removeItem(storageKey($bar));
      } catch (err) {}
      fetchList($bar);
    });

    $bar.on('click', '.wwp-admin-list-export', function (e) {
      e.preventDefault();
      exportCsv($bar);
    });

    // Enter / form submit = Apply filter (no page reload)
    $bar.on('submit', '.wwp-admin-list-form', function (e) {
      e.preventDefault();
      $bar.find('[name=paged]').val(1);
      fetchList($bar);
    });

    $bar.on('keydown', '.wwp-admin-list-form input, .wwp-admin-list-form select', function (e) {
      if (e.key !== 'Enter' && e.keyCode !== 13) return;
      // Keep Enter usable inside select2 search boxes
      if ($(e.target).hasClass('select2-search__field')) return;
      e.preventDefault();
      $bar.find('[name=paged]').val(1);
      fetchList($bar);
    });
  }

  $(function () {
    $('[data-wwp-admin-list]').each(function () {
      initBar($(this));
    });

    function findBarFor($el) {
      var $scope = $el.closest('.wwp-details-inner, .wwp-user-panel-details, [data-wwp-list-scope]');
      var $bar = $scope.length ? $scope.find('[data-wwp-admin-list]').first() : $();
      if (!$bar.length) {
        $bar = $el.closest('.wallet-admin-wrap, .wrap').find('[data-wwp-admin-list]').first();
      }
      return $bar;
    }

    $(document).on('click', '[data-wwp-list-pager] a[data-page], .wallet-pagination a[data-page]', function (e) {
      e.preventDefault();
      var page = $(this).data('page');
      var $bar = findBarFor($(this));
      if (!$bar.length) return;
      fetchList($bar, { page: page });
    });

    $(document).on('click', 'th.wwp-sortable .wwp-sort-link', function (e) {
      e.preventDefault();
      var $th = $(this).closest('th');
      var orderby = $th.data('orderby');
      var $bar = findBarFor($th);
      if (!$bar.length || !orderby) return;
      var curBy = $bar.find('[name=orderby]').val();
      var curOrd = $bar.find('[name=order]').val() || 'DESC';
      var nextOrd = curBy === orderby && curOrd === 'DESC' ? 'ASC' : 'DESC';
      fetchList($bar, { orderby: orderby, order: nextOrd, page: 1 });
    });

    // Bulk helpers
    $(document).on('click', '[data-wwp-bulk]', function (e) {
      e.preventDefault();
      var $btn = $(this);
      var kind = $btn.data('wwp-bulk');
      var $wrap = $btn.closest('.wallet-admin-wrap, .wrap');
      var ids = selectedIds($wrap);
      if (!ids.length) {
        alert(wwpAdminList.i18n.selectRows);
        return;
      }
      if (!confirm(wwpAdminList.i18n.confirmBulk)) return;

      var $form = $('<form method="post" action="' + wwpAdminList.adminPostUrl + '"></form>');
      if (kind === 'withdraw-approve' || kind === 'withdraw-cancel') {
        $form.append($('<input type="hidden" name="action">').val('wallet_bulk_update_withdrawals'));
        $form.append($('<input type="hidden" name="wallet_bulk_nonce">').val(wwpAdminList.bulkNonces.withdrawals));
        $form.append($('<input type="hidden" name="new_status">').val(kind === 'withdraw-approve' ? 'completed' : 'canceled'));
      } else if (kind === 'trade-cancel') {
        $form.append($('<input type="hidden" name="action">').val('wallet_bulk_cancel_trades'));
        $form.append($('<input type="hidden" name="wallet_bulk_nonce">').val(wwpAdminList.bulkNonces.trades));
        $form.append($('<input type="hidden" name="refund_balance" value="1">'));
      } else if (kind === 'manual-approve' || kind === 'manual-reject') {
        $form.append($('<input type="hidden" name="action">').val('wallet_bulk_manual_trade_action'));
        $form.append($('<input type="hidden" name="wallet_bulk_nonce">').val(wwpAdminList.bulkNonces.manual));
        $form.append($('<input type="hidden" name="bulk_action">').val(kind === 'manual-approve' ? 'approve' : 'reject'));
      } else if (kind === 'doc-approve' || kind === 'doc-reject') {
        $form.append($('<input type="hidden" name="action">').val('wallet_bulk_document_action'));
        $form.append($('<input type="hidden" name="wallet_bulk_nonce">').val(wwpAdminList.bulkNonces.documents));
        $form.append($('<input type="hidden" name="bulk_action">').val(kind === 'doc-approve' ? 'approve' : 'reject'));
        if (kind === 'doc-reject') {
          var reason = window.prompt('دلیل رد مدارک انتخاب‌شده:', 'رد گروهی توسط ادمین');
          if (!reason) return;
          $form.append($('<input type="hidden" name="rejection_reason">').val(reason));
        }
      } else {
        return;
      }
      ids.forEach(function (id) {
        $form.append($('<input type="hidden" name="ids[]">').val(id));
      });
      $('body').append($form);
      $form.trigger('submit');
    });

    $(document).on('change', '.wwp-check-all', function () {
      var on = this.checked;
      $(this).closest('.wallet-admin-wrap, .wrap').find('.wwp-row-check').prop('checked', on);
    });

    // Dashboard quick lookup
    $(document).on('click', '#wwp-admin-lookup-btn', function (e) {
      e.preventDefault();
      var q = $.trim($('#wwp-admin-lookup-q').val() || '');
      if (!q) return;
      $.post(wwpAdminList.ajaxUrl, {
        action: 'mtw_admin_lookup',
        nonce: wwpAdminList.nonce,
        q: q
      }).done(function (res) {
        if (res && res.success && res.data && res.data.url) {
          window.location.href = res.data.url;
        } else {
          alert((res && res.data && res.data.message) || 'یافت نشد');
        }
      }).fail(function () {
        alert(wwpAdminList.i18n.error);
      });
    });

    // Settings: clear localStorage filter memory
    $(document).on('click', '#wwp-clear-admin-list-memory', function (e) {
      e.preventDefault();
      var removed = 0;
      try {
        var keys = [];
        for (var i = 0; i < localStorage.length; i++) {
          var k = localStorage.key(i);
          if (k && k.indexOf('mtw_admin_list_state:') === 0) keys.push(k);
        }
        keys.forEach(function (k) {
          localStorage.removeItem(k);
          removed++;
        });
      } catch (err) {}
      alert(wwpAdminList.i18n.cleared + ' (' + removed + ')');
    });

    // Jump nav: open target accordion before scrolling
    $(document).on('click', '.wwp-panel-jumpnav a[href^="#"]', function () {
      var id = $(this).attr('href');
      if (!id || id === '#') {
        return;
      }
      var el = document.querySelector(id);
      if (el && el.tagName === 'DETAILS') {
        el.open = true;
      }
    });

    // Client-side assets filter
    $(document).on('input change', '#wwp-assets-filter-q, #wwp-assets-filter-active', function () {
      var q = (($('#wwp-assets-filter-q').val() || '') + '').toLowerCase();
      var onlyActive = $('#wwp-assets-filter-active').is(':checked');
      $('#wwp-assets-table tbody tr').each(function () {
        var $tr = $(this);
        var text = ($tr.text() || '').toLowerCase();
        var active = String($tr.data('active')) === '1';
        var show = (!q || text.indexOf(q) !== -1) && (!onlyActive || active);
        $tr.toggle(show);
      });
    });
  });
})(jQuery);
