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

  function post(action, extra) {
    var c = cfg();
    var data = new FormData();
    data.append('action', action);
    data.append('nonce', String(c.nonce || ''));
    if (extra) {
      Object.keys(extra).forEach(function (key) {
        data.append(key, String(extra[key]));
      });
    }
    return fetch(String(c.ajax_url || ''), { method: 'POST', body: data }).then(function (res) {
      return res.json();
    });
  }

  // -------------------------------------------------------------------------
  // KPI card updaters
  // -------------------------------------------------------------------------
  function updateKpiCards(newOptimized, total, savedBytes) {
    var pending = Math.max(0, total - newOptimized);

    var elTotal    = document.getElementById('kitgenix-image-optimizer-stat-total');
    var elOptimized = document.getElementById('kitgenix-image-optimizer-stat-optimized');
    var elPending  = document.getElementById('kitgenix-image-optimizer-stat-pending');
    var elSavings  = document.getElementById('kitgenix-image-optimizer-stat-savings');

    if (elTotal)    elTotal.textContent    = formatNumber(total);
    if (elOptimized) elOptimized.textContent = formatNumber(newOptimized);
    if (elPending)  elPending.textContent  = formatNumber(pending);
    if (elSavings)  elSavings.textContent  = formatBytes(savedBytes);
  }

  function updateProgressBar(current, total) {
    var fill  = document.getElementById('kitgenix-image-optimizer-progress-fill');
    var label = document.getElementById('kitgenix-image-optimizer-progress-label');
    if (!fill && !label) return;

    var pct = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;

    if (fill)  fill.style.width = pct + '%';
    if (label) label.textContent = formatNumber(current) + ' ' + i18n('of', 'of') + ' ' + formatNumber(total) + ' ' + i18n('images', 'images') + ' – ' + pct + '%';
  }

  function updateRetryButton(failedCount) {
    var retryBtn = document.getElementById('kitgenix-image-optimizer-bulk-retry');
    if (!retryBtn) return;
    var count = parseInt(failedCount, 10) || 0;
    if (count > 0) {
      retryBtn.style.display = '';
      retryBtn.textContent = i18n('retryFailed', 'Retry %d Failed').replace('%d', formatNumber(count));
    } else {
      retryBtn.style.display = 'none';
    }
  }

  // -------------------------------------------------------------------------
  // Bulk queue (server-persisted; survives a closed browser tab via a
  // scheduled background task, and pause/resume/retry are real queue states,
  // not just a client-side flag).
  // -------------------------------------------------------------------------
  var pollTimer = null;
  var isPolling = false;

  function initBulk() {
    var startBtn   = document.getElementById('kitgenix-image-optimizer-bulk-start');
    var reoptBtn   = document.getElementById('kitgenix-image-optimizer-bulk-reoptimize');
    var retryBtn   = document.getElementById('kitgenix-image-optimizer-bulk-retry');
    var statusSpan = document.getElementById('kitgenix-image-optimizer-bulk-status');

    if (!startBtn) return;

    var c = cfg();
    var queue = c.queue || {};

    function setRunningUI(mode) {
      startBtn.textContent = i18n('bulkPause', 'Pause');
      startBtn.dataset.mode = 'bulk';
      if (reoptBtn) {
        if (mode === 'reoptimize') {
          reoptBtn.textContent = i18n('bulkPause', 'Pause');
          reoptBtn.dataset.mode = 'reoptimize';
        } else {
          reoptBtn.setAttribute('disabled', 'disabled');
        }
      }
    }

    function setIdleUI() {
      startBtn.textContent = i18n('bulkStart', 'Start Bulk Optimization');
      delete startBtn.dataset.mode;
      if (reoptBtn) {
        reoptBtn.removeAttribute('disabled');
        reoptBtn.textContent = i18n('bulkReoptimizeStart', 'Bulk Re-Optimize');
        delete reoptBtn.dataset.mode;
      }
    }

    function poll() {
      if (isPolling) return;
      isPolling = true;

      post('kitgenix_image_optimizer_queue_next', {}).then(function (json) {
        isPolling = false;
        if (!json.success) {
          stopPolling();
          if (statusSpan) {
            statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-error';
            statusSpan.textContent = (json.data && json.data.message) ? String(json.data.message) : i18n('error', 'An error occurred. Please try again.');
          }
          return;
        }

        var data = json.data || {};

        if (data.status === 'paused') {
          stopPolling();
          return;
        }

        if (data.done || data.status === 'completed' || data.idle) {
          stopPolling();
          setIdleUI();
          updateProgressBar(data.total || 0, data.total || 0);
          updateKpiCards(data.total || 0, data.total || 0, data.savings_bytes || 0);
          updateRetryButton(data.failed_count || 0);
          if (statusSpan) {
            statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-done';
            statusSpan.textContent = i18n('done', 'All done! Every image has been optimized.');
          }
          return;
        }

        if (data.attachment_id) {
          updateRowStatus(parseInt(data.attachment_id, 10), data.savings_bytes || 0, data.savings_percent || 0);
        }

        var total = parseInt(data.total, 10) || 0;
        var done  = parseInt(data.done_estimate, 10) || parseInt(data.processed, 10) || 0;

        updateProgressBar(done, total);
        updateKpiCards(parseInt(cfg().optimized, 10) + (data.mode === 'bulk' ? done : 0) || done, total, data.savings_bytes || 0);
        updateRetryButton(data.failed_count || 0);

        if (statusSpan) {
          statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-running';
          var pct = total > 0 ? Math.round((done / total) * 100) : 0;
          statusSpan.textContent = formatNumber(done) + ' / ' + formatNumber(total) + ' (' + pct + '%) – ' + formatBytes(data.savings_bytes || 0) + ' ' + i18n('saved', 'saved');
        }

        // Keep going while the queue reports "running".
        if (data.status === 'running') {
          pollTimer = window.setTimeout(poll, 150);
        } else {
          stopPolling();
        }
      }).catch(function () {
        isPolling = false;
        stopPolling();
        if (statusSpan) {
          statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-error';
          statusSpan.textContent = i18n('error', 'An error occurred. Please try again.');
        }
      });
    }

    function stopPolling() {
      if (pollTimer) {
        window.clearTimeout(pollTimer);
        pollTimer = null;
      }
    }

    function startQueue(mode) {
      post('kitgenix_image_optimizer_queue_start', { mode: mode }).then(function (json) {
        if (!json.success) return;
        setRunningUI(mode);
        if (statusSpan) {
          statusSpan.className = 'kitgenix-image-optimizer-bulk-status is-running';
          statusSpan.textContent = i18n('optimizing', 'Optimizing…');
        }
        poll();
      });
    }

    function pauseQueue() {
      post('kitgenix_image_optimizer_queue_pause', {}).then(function () {
        stopPolling();
        setIdleUI();
        startBtn.textContent = i18n('bulkStart', 'Start Bulk Optimization');
        if (statusSpan) {
          statusSpan.className = 'kitgenix-image-optimizer-bulk-status';
          statusSpan.textContent = i18n('bulkPause', 'Paused');
        }
      });
    }

    startBtn.addEventListener('click', function () {
      if (startBtn.dataset.mode === 'bulk') {
        pauseQueue();
        return;
      }
      if (reoptBtn && reoptBtn.dataset.mode === 'reoptimize') return; // reoptimize running
      startQueue('bulk');
    });

    if (reoptBtn) {
      reoptBtn.addEventListener('click', function () {
        if (reoptBtn.dataset.mode === 'reoptimize') {
          pauseQueue();
          return;
        }
        if (startBtn.dataset.mode === 'bulk') return; // bulk running
        startQueue('reoptimize');
      });
    }

    if (retryBtn) {
      retryBtn.addEventListener('click', function () {
        retryBtn.textContent = i18n('retrying', 'Retrying…');
        post('kitgenix_image_optimizer_queue_retry', {}).then(function (json) {
          if (!json.success) return;
          setRunningUI('bulk');
          poll();
        });
      });
    }

    // Resume automatically if the queue was left running/paused server-side
    // (e.g. this page was reloaded mid-run, or the background task is active).
    if (queue.status === 'running') {
      setRunningUI(queue.mode || 'bulk');
      poll();
    }
    updateRetryButton(queue.failed_count || 0);
  }

  // -------------------------------------------------------------------------
  // Recalculate stats (explicit, admin-triggered full recount)
  // -------------------------------------------------------------------------
  function initRecalculate() {
    var btn = document.getElementById('kitgenix-image-optimizer-recalculate-stats');
    if (!btn) return;

    btn.addEventListener('click', function () {
      var original = btn.textContent;
      btn.setAttribute('disabled', 'disabled');
      btn.textContent = i18n('recalculating', 'Recalculating…');

      post('kitgenix_image_optimizer_recalculate_stats', {}).then(function (json) {
        btn.removeAttribute('disabled');
        btn.textContent = i18n('recalculated', 'Stats recalculated.');
        if (json.success && json.data) {
          updateKpiCards(json.data.optimized || 0, parseInt(cfg().total, 10) || 0, json.data.savings_bytes || 0);
        }
        window.setTimeout(function () { btn.textContent = original; }, 2500);
      }).catch(function () {
        btn.removeAttribute('disabled');
        btn.textContent = original;
      });
    });
  }

  // -------------------------------------------------------------------------
  // Single image optimization / restore buttons (Images tab table)
  // -------------------------------------------------------------------------
  function initSingleOptimize() {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.kitgenix-image-optimizer-optimize-single') : null;
      if (!btn) return;

      var attachmentId = parseInt(btn.getAttribute('data-attachment-id') || '0', 10);
      if (!attachmentId) return;

      btn.setAttribute('disabled', 'disabled');
      btn.textContent = i18n('optimizing', 'Optimizing…');

      post('kitgenix_image_optimizer_optimize_single', { attachment_id: attachmentId }).then(function (json) {
        if (json.success) {
          var imgSavings = parseInt((json.data && json.data.savings_bytes) || 0, 10);
          var imgPct     = parseFloat((json.data && json.data.savings_percent) || 0);
          updateRowStatus(attachmentId, imgSavings, imgPct);
          btn.textContent = i18n('optimized', 'Optimized');
          btn.removeAttribute('disabled');
        } else {
          btn.removeAttribute('disabled');
          btn.textContent = 'Optimize';
        }
      }).catch(function () {
        btn.removeAttribute('disabled');
        btn.textContent = 'Optimize';
      });
    });

    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.kitgenix-image-optimizer-restore-backup') : null;
      if (!btn) return;

      if (!window.confirm(i18n('restoreConfirm', 'Restore original image? This will undo the optimization.'))) return;

      var attachmentId = parseInt(btn.getAttribute('data-attachment-id') || '0', 10);
      if (!attachmentId) return;

      btn.setAttribute('disabled', 'disabled');
      btn.textContent = i18n('restoring', 'Restoring…');

      post('kitgenix_image_optimizer_restore_backup', { attachment_id: attachmentId }).then(function (json) {
        if (json.success) {
          window.location.reload();
        } else {
          btn.removeAttribute('disabled');
          btn.textContent = i18n('restoreFail', 'Restore failed');
        }
      }).catch(function () {
        btn.removeAttribute('disabled');
        btn.textContent = i18n('restoreFail', 'Restore failed');
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
      statusCell.innerHTML = '<span class="kitgenix-badge success">' + i18n('optimized', 'Optimized') + '</span>';
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
  function initAll() {
    initBulk();
    initSingleOptimize();
    initRecalculate();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

}());
