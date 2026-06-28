/**
 * Mensajes flash (sesión, URL y alertas en página) → SweetAlert2.
 */
(function () {
  'use strict';

  var SWAL_COLOR = '#CE647E';

  function iconFor(type) {
    var map = { success: 'success', error: 'error', danger: 'error', warning: 'warning', info: 'info' };
    return map[type] || 'info';
  }

  function titleFor(type) {
    var map = {
      success: 'Éxito',
      error: 'Error',
      danger: 'Error',
      warning: 'Atención',
      info: 'Información',
    };
    return map[type] || 'Información';
  }

  function showFlash(type, message) {
    if (!message || typeof Swal === 'undefined') {
      return;
    }
    Swal.fire({
      icon: iconFor(type),
      title: titleFor(type),
      text: message,
      confirmButtonText: 'Aceptar',
      confirmButtonColor: SWAL_COLOR,
    });
  }

  function textFromAlert(el) {
    var clone = el.cloneNode(true);
    clone.querySelectorAll('button, .btn-close, i').forEach(function (node) {
      node.remove();
    });
    return (clone.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function typeFromAlert(el) {
    if (el.classList.contains('alert-success')) return 'success';
    if (el.classList.contains('alert-danger')) return 'error';
    if (el.classList.contains('alert-warning')) return 'warning';
    return 'info';
  }

  function isFlashAlert(el) {
    if (!el || !el.classList || !el.classList.contains('alert')) return false;
    if (el.dataset.swallow === '0') return false;
    if (el.closest('.modal') || el.closest('[role="dialog"]')) return false;
    if (el.dataset.swalProcessed === '1') return false;
    if (el.classList.contains('alert-light') && el.closest('form')) return false;
    return (
      el.classList.contains('alert-success')
      || el.classList.contains('alert-danger')
      || el.classList.contains('alert-warning')
      || el.classList.contains('alert-info')
      || el.classList.contains('alert-dismissible')
    );
  }

  function processAlerts(root) {
    var scope = root || document;
    scope.querySelectorAll('.alert').forEach(function (el) {
      if (!isFlashAlert(el)) return;
      var text = textFromAlert(el);
      if (text === '') return;
      el.dataset.swalProcessed = '1';
      showFlash(typeFromAlert(el), text);
      el.remove();
    });
  }

  function processUrlParams() {
    var params = new URLSearchParams(window.location.search);
    var changed = false;
    [['success', 'success'], ['error', 'error'], ['warning', 'warning'], ['info', 'info']].forEach(function (pair) {
      var val = params.get(pair[0]);
      if (val) {
        showFlash(pair[1], val);
        params.delete(pair[0]);
        changed = true;
      }
    });
    if (changed) {
      var qs = params.toString();
      var newUrl = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
      window.history.replaceState({}, '', newUrl);
    }
  }

  function processJsonFlash() {
    var dataEl = document.getElementById('app-flash-data');
    if (!dataEl) return;
    try {
      var items = JSON.parse(dataEl.textContent || '[]');
      if (!Array.isArray(items)) return;
      items.forEach(function (item) {
        if (item && item.message) {
          showFlash(item.type || 'info', item.message);
        }
      });
    } catch (e) {
      /* ignorar JSON inválido */
    }
  }

  function init() {
    if (typeof Swal === 'undefined') {
      setTimeout(init, 40);
      return;
    }
    processJsonFlash();
    processUrlParams();
    processAlerts(document.getElementById('app-flash-messages'));
    processAlerts(document.querySelector('main'));
  }

  window.AppFlashSwal = {
    show: showFlash,
    processPageAlerts: function () {
      processAlerts(document.querySelector('main'));
    },
    init: init,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
