(function () {
  'use strict';

  function cfg() {
    return window.kitgenix_image_optimizer_admin || {};
  }

  function i18n(key, fallback) {
    try {
      var c = cfg();
      return (c && c.i18n && c.i18n[key]) ? String(c.i18n[key]) : fallback;
    } catch (_e) {
      return fallback;
    }
  }

  /** Format bytes → '1.23 MB' */
  function formatBytes(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes <= 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    i = Math.min(i, units.length - 1);
    return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
  }

  /** Format integer with locale thousands separator */
  function formatNumber(n) {
    return (parseInt(n, 10) || 0).toLocaleString();
  }

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------
  var isBulkRunning = false;
  var bulkStopped   = false;
  var bulkTotal     = 0;
  var bulkOptimized = 0;
  var bulkSaved     = 0; // bytes accumulated this session

  var isReoptRunning = false;
  var reoptStopped   = false;
  var reoptOffset    = 0;
  var reoptProcessed = 0;
  var reoptSaved     = 0;

  // -------------------------------------------------------------------------
  // KPI card updaters
  // -------------------------------------------------------------------------
  function updateKpiCards(newOptimized, savedBytes) {
    var c = cfg();
    var total     = parseInt(c.total, 10) || 0;
    var optimized = Math.min(newOptimized, total);
    var pending   = Math.max(0, total - optimized);

    var elTotal    = document.getElementById('kitgenix-image-optimizer-stat-total');
    var elOptimized = document.getElementById('kitgenix-image-optimizer-stat-optimized');
    var elPending  = document.getElementById('kitgenix-image-optimizer-stat-pending');
    var elSavings  = document.getElementById('kitgenix-image-optimizer-stat-savings');

    if (elTotal)    elTotal.textContent    = formatNumber(total);
    if (elOptimized) elOptimized.textContent = formatNumber(optimized);
    if (elPending)  elPending.textContent  = formatNumber(pending);
    if (elSavings)  elSavings.textContent  = formatBytes(savedBytes);
  }

  function updateProgressBar(current, total) {
    var fill  = document.getElementById('kitgenix-image-optimizer-progress-fill');
    var label = document.getElementById('kitgenix-image-optimizer-progress-label');
    if (!fill && !label) return;

    var pct = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;

    if (fill)  fill.style.width = pct + '%';
    if (label) label.textContent = formatNumber(current) + ' ' + i18n('of', 'of') + ' ' + formatNumber(total) + ' ' + i18n('images', 'images') + ' — ' + pct + '%';
  }

  // -------------------------------------------------------------------------
  // Bulk optimization
  // -------------------------------------------------------------------------
  function initBulk() {
    var startBtn   = document.getElementById('kitgenix-image-optimizer-bulk-start');
    var reoptBtn   = document.getElementById('kitgenix-image-optimizer-bulk-reoptimize');
    var statusSpan = document.getElementById('kitgenix-image-optimizer-bulk-status');

    if (!startBtn) return;

    // Seed session counters from server-side data.
    var c = cfg();
    bulkTotal     = parseInt(c.total, 10) || 0;
    bulkOptimized = parseInt(c.optimized, 10) || 0;
    bulkSaved     = parseInt(c.savings_bytes, 10) || 0;

    startBtn.addEventListener('click', function () {
      if (isBulkRunning) {
        bulkStopped  = true;
        isBulkRunning = false;
        startBtn.textContent = i18n('bulkStart', 'Start Bulk Optimization');
        if (reoptBtn) reoptBtn.removeAttribute('disabled');
        if (statusSpan) {
          statusSpan.className = 'kitgenix-image-optimizer-bulk-status';
          statusSpan.textContent = '';
        }
        return;
      }

      // Don't start if re-optimize is already running.
      if (isReoptRunning) return;

      bulkStopped  = false;
      isBulkRunning = true;
      startBtn.textContent = i18n('bulkStop', 'Stop');
      if (reoptBtn) reoptBtn.setAttribute('disabled', 'disabled');

      if (statusSpan) {
        statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-running';
        statusSpan.textContent = i18n('optimizing', 'Optimizing…');
      }

      processNext();
    });
  }

  function processNext() {
    if (!isBulkRunning || bulkStopped) return;

    var c = cfg();
    if (!c.ajax_url || !c.nonce) return;

    var data = new FormData();
    data.append('action', 'kitgenix_image_optimizer_bulk_next');
    data.append('nonce', String(c.nonce));

    fetch(String(c.ajax_url), { method: 'POST', body: data })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!isBulkRunning || bulkStopped) return;

        var startBtn   = document.getElementById('kitgenix-image-optimizer-bulk-start');
        var statusSpan = document.getElementById('kitgenix-image-optimizer-bulk-status');

        if (!json.success) {
          isBulkRunning = false;
          if (startBtn) startBtn.textContent = i18n('bulkStart', 'Start Bulk Optimization');
          var _rb1 = document.getElementById('kitgenix-image-optimizer-bulk-reoptimize');
          if (_rb1) _rb1.removeAttribute('disabled');
          if (statusSpan) {
            statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-error';
            statusSpan.textContent = (json.data && json.data.message) ? String(json.data.message) : i18n('error', 'An error occurred. Please try again.');
          }
          return;
        }

        if (json.data && json.data.done) {
          isBulkRunning = false;
          var _rb2 = document.getElementById('kitgenix-image-optimizer-bulk-reoptimize');
          if (_rb2) _rb2.removeAttribute('disabled');
          if (startBtn) {
            startBtn.textContent = i18n('allOptimized', 'All Images Optimized');
            startBtn.setAttribute('disabled', 'disabled');
          }
          updateProgressBar(bulkTotal, bulkTotal);
          updateKpiCards(bulkTotal, bulkSaved);
          if (statusSpan) {
            statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-done';
            statusSpan.textContent = i18n('done', 'All done! Every image has been optimized.');
          }
          return;
        }

        // Accumulate savings from this image.
        var imgSavings = parseInt((json.data && json.data.savings_bytes) || 0, 10);
        bulkSaved     += imgSavings;
        bulkOptimized += 1;

        // Update row in table.
        if (json.data && json.data.attachment_id) {
          updateRowStatus(
            parseInt(json.data.attachment_id, 10),
            json.data.savings_bytes || 0,
            json.data.savings_percent || 0
          );
        }

        updateKpiCards(bulkOptimized, bulkSaved);
        updateProgressBar(bulkOptimized, bulkTotal);

        // Live status text.
        if (statusSpan) {
          var pct = bulkTotal > 0 ? Math.round((bulkOptimized / bulkTotal) * 100) : 0;
          statusSpan.textContent = formatNumber(bulkOptimized) + ' / ' + formatNumber(bulkTotal) + ' (' + pct + '%) — ' + formatBytes(bulkSaved) + ' ' + i18n('saved', 'saved');
        }

        // Continue to next image.
        processNext();
      })
      .catch(function () {
        isBulkRunning = false;
        var startBtn   = document.getElementById('kitgenix-image-optimizer-bulk-start');
        var reoptBtn   = document.getElementById('kitgenix-image-optimizer-bulk-reoptimize');
        var statusSpan = document.getElementById('kitgenix-image-optimizer-bulk-status');
        if (startBtn) startBtn.textContent = i18n('bulkStart', 'Start Bulk Optimization');
        if (reoptBtn) reoptBtn.removeAttribute('disabled');
        if (statusSpan) {
          statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-error';
          statusSpan.textContent = i18n('error', 'An error occurred. Please try again.');
        }
      });
  }

  // -------------------------------------------------------------------------
  // Bulk Re-Optimize (re-processes ALL images, including already-optimised ones)
  // -------------------------------------------------------------------------
  function initBulkReoptimize() {
    var btn        = document.getElementById('kitgenix-image-optimizer-bulk-reoptimize');
    var startBtn   = document.getElementById('kitgenix-image-optimizer-bulk-start');
    var statusSpan = document.getElementById('kitgenix-image-optimizer-bulk-status');

    if (!btn) return;

    btn.addEventListener('click', function () {
      if (isReoptRunning) {
        // Stop re-optimize.
        reoptStopped   = true;
        isReoptRunning = false;
        btn.textContent = i18n('bulkReoptimizeStart', 'Bulk Re-Optimize');
        if (startBtn) startBtn.removeAttribute('disabled');
        if (statusSpan) {
          statusSpan.className   = 'kitgenix-image-optimizer-bulk-status';
          statusSpan.textContent = '';
        }
        return;
      }

      // Don't start if normal bulk is already running.
      if (isBulkRunning) return;

      reoptStopped   = false;
      isReoptRunning = true;
      reoptOffset    = 0;
      reoptProcessed = 0;
      reoptSaved     = 0;
      btn.textContent = i18n('bulkStop', 'Stop');
      if (startBtn) startBtn.setAttribute('disabled', 'disabled');

      if (statusSpan) {
        statusSpan.className   = 'kitgenix-image-optimizer-bulk-status is-running';
        statusSpan.textContent = i18n('optimizing', 'Optimizing…');
      }

      processReoptimizeNext();
    });
  }

  function processReoptimizeNext() {
    if (!isReoptRunning || reoptStopped) return;

    var c = cfg();
    if (!c.ajax_url || !c.nonce) return;

    var total = parseInt(c.total, 10) || 0;

    var data = new FormData();
    data.append('action', 'kitgenix_image_optimizer_bulk_reoptimize_next');
    data.append('nonce', String(c.nonce));
    data.append('offset', String(reoptOffset));

    fetch(String(c.ajax_url), { method: 'POST', body: data })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!isReoptRunning || reoptStopped) return;

        var btn        = document.getElementById('kitgenix-image-optimizer-bulk-reoptimize');
        var startBtn   = document.getElementById('kitgenix-image-optimizer-bulk-start');
        var statusSpan = document.getElementById('kitgenix-image-optimizer-bulk-status');

        if (!json.success) {
          isReoptRunning = false;
          if (btn)      btn.textContent = i18n('bulkReoptimizeStart', 'Bulk Re-Optimize');
          if (startBtn) startBtn.removeAttribute('disabled');
          if (statusSpan) {
            statusSpan.className   = 'kitgenix-image-optimizer-bulk-status is-error';
            statusSpan.textContent = (json.data && json.data.message) ? String(json.data.message) : i18n('error', 'An error occurred. Please try again.');
          }
          return;
        }

        if (json.data && json.data.done) {
          isReoptRunning = false;
          if (btn)      btn.textContent = i18n('bulkReoptimizeStart', 'Bulk Re-Optimize');
          if (startBtn) startBtn.removeAttribute('disabled');
          updateProgressBar(total, total);
          updateKpiCards(total, reoptSaved);
          if (statusSpan) {
            statusSpan.className   = 'kitgenix-image-optimizer-bulk-status is-done';
            statusSpan.textContent = i18n('reoptimizeDone', 'All images have been re-optimized.');
          }
          return;
        }

        var imgSavings  = parseInt((json.data && json.data.savings_bytes) || 0, 10);
        reoptSaved     += imgSavings;
        reoptOffset     = parseInt((json.data && json.data.next_offset) || (reoptOffset + 1), 10);
        reoptProcessed += 1;

        if (json.data && json.data.attachment_id) {
          updateRowStatus(
            parseInt(json.data.attachment_id, 10),
            json.data.savings_bytes || 0,
            json.data.savings_percent || 0
          );
        }

        updateProgressBar(reoptProcessed, total);

        if (statusSpan) {
          var pct = total > 0 ? Math.round((reoptProcessed / total) * 100) : 0;
          statusSpan.textContent = formatNumber(reoptProcessed) + ' / ' + formatNumber(total) + ' (' + pct + '%) — ' + formatBytes(reoptSaved) + ' ' + i18n('saved', 'saved');
        }

        processReoptimizeNext();
      })
      .catch(function () {
        isReoptRunning = false;
        var btn        = document.getElementById('kitgenix-image-optimizer-bulk-reoptimize');
        var startBtn   = document.getElementById('kitgenix-image-optimizer-bulk-start');
        var statusSpan = document.getElementById('kitgenix-image-optimizer-bulk-status');
        if (btn)      btn.textContent = i18n('bulkReoptimizeStart', 'Bulk Re-Optimize');
        if (startBtn) startBtn.removeAttribute('disabled');
        if (statusSpan) {
          statusSpan.className   = 'kitgenix-image-optimizer-bulk-status is-error';
          statusSpan.textContent = i18n('error', 'An error occurred. Please try again.');
        }
      });
  }

  // -------------------------------------------------------------------------
  // Single image optimization buttons
  // -------------------------------------------------------------------------
  function initSingleOptimize() {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.kitgenix-image-optimizer-optimize-single') : null;
      if (!btn) return;

      var attachmentId = parseInt(btn.getAttribute('data-attachment-id') || '0', 10);
      if (!attachmentId) return;

      var c = cfg();
      if (!c.ajax_url || !c.nonce) return;

      btn.setAttribute('disabled', 'disabled');
      btn.textContent = i18n('optimizing', 'Optimizing…');

      var data = new FormData();
      data.append('action', 'kitgenix_image_optimizer_optimize_single');
      data.append('nonce', String(c.nonce));
      data.append('attachment_id', String(attachmentId));

      fetch(String(c.ajax_url), { method: 'POST', body: data })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json.success) {
            var imgSavings = parseInt((json.data && json.data.savings_bytes) || 0, 10);
            var imgPct     = parseFloat((json.data && json.data.savings_percent) || 0);
            bulkSaved     += imgSavings;
            bulkOptimized += 1;

            updateRowStatus(attachmentId, imgSavings, imgPct);
            updateKpiCards(bulkOptimized, bulkSaved);
            updateProgressBar(bulkOptimized, bulkTotal);
            btn.textContent = i18n('optimized', 'Optimized');
          } else {
            btn.removeAttribute('disabled');
            btn.textContent = 'Optimize';
          }
        })
        .catch(function () {
          btn.removeAttribute('disabled');
          btn.textContent = 'Optimize';
        });
    });
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------
  function updateRowStatus(attachmentId, savingsBytes, savingsPct) {
    var row = document.getElementById('kitgenix-image-optimizer-row-' + attachmentId);
    if (!row) return;

    var statusCell  = row.querySelector('.kitgenix-image-optimizer-status-cell');
    var savingsCell = row.querySelector('.kitgenix-image-optimizer-savings-cell');

    if (statusCell) {
      statusCell.innerHTML = '<span class="kitgenix-badge ok">' + i18n('optimized', 'Optimized') + '</span>';
    }

    if (savingsCell) {
      var bytes = parseInt(savingsBytes, 10) || 0;
      var pct   = parseFloat(savingsPct) || 0;
      if (bytes > 0) {
        savingsCell.innerHTML = '<span class="kitgenix-image-optimizer-savings-chip">&darr; ' + pct.toFixed(1) + '% &middot; ' + formatBytes(bytes) + '</span>';
      } else {
        savingsCell.innerHTML = '<span class="kitgenix-image-optimizer-savings-chip is-none">&mdash;</span>';
      }
    }
  }

  // -------------------------------------------------------------------------
  // Init
  // -------------------------------------------------------------------------
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initBulk();
      initSingleOptimize();
      initBulkReoptimize();
    });
  } else {
    initBulk();
    initSingleOptimize();
    initBulkReoptimize();
  }

}());
