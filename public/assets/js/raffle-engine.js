/**
 * Sorteos de premios (torneo) — UI + llamadas a api/tournament_raffle.php
 */
(function () {
  var cfg = window.RAFFLE_CFG || {};
  var torneoId = cfg.torneo_id || 0;
  var csrf = cfg.csrf_token || '';
  var apiUrl = cfg.api || 'api/tournament_raffle.php';

  function post(action, extra) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('torneo_id', String(torneoId));
    body.set('csrf_token', csrf);
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        body.set(k, extra[k]);
      });
    }
    return fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json();
    });
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function refreshCount() {
    post('list')
      .then(function (data) {
        var el = document.getElementById('rp-count-hint');
        if (!el) return;
        if (data && data.ok) {
          el.textContent = 'Inscritos elegibles: ' + (data.total || 0);
        } else {
          el.textContent = 'No se pudo cargar lista de inscritos.';
        }
      })
      .catch(function () {
        var el = document.getElementById('rp-count-hint');
        if (el) el.textContent = 'Error de red al cargar inscritos.';
      });
  }

  function refreshHistorial() {
    var box = document.getElementById('rp-historial');
    if (!box) return;
    post('historial')
      .then(function (data) {
        if (!data || !data.ok || !data.lotes || !data.lotes.length) {
          box.innerHTML = '<p class="rp-muted">Aún no hay sorteos registrados.</p>';
          return;
        }
        var html = '<div class="rp-lotes">';
        data.lotes.forEach(function (lote) {
          html += '<div class="rp-lote">';
          html +=
            '<div class="rp-lote-head">' +
            esc(lote.created_at) +
            ' — <strong>' +
            esc(lote.premio_label || 'Premio') +
            '</strong></div><ul>';
          (lote.items || []).forEach(function (it) {
            html +=
              '<li>' +
              esc(it.nombre || '') +
              ' <span class="rp-id">(ID ' +
              esc(it.id_usuario) +
              ')</span></li>';
          });
          html += '</ul></div>';
        });
        html += '</div>';
        box.innerHTML = html;
      })
      .catch(function () {
        box.innerHTML = '<p class="rp-error">No se pudo cargar el historial.</p>';
      });
  }

  function runSorteo() {
    var stage = document.getElementById('rp-stage');
    var premio = (document.getElementById('rp-premio') || {}).value || 'Premio';
    var cant = parseInt((document.getElementById('rp-cantidad') || {}).value || '1', 10) || 1;
    var exclPrev = (document.getElementById('rp-excl-prev') || {}).checked;
    var exclIds = (document.getElementById('rp-excl-ids') || {}).value || '';

    if (stage) {
      stage.innerHTML =
        '<div class="rp-spin"><i class="fas fa-dice"></i> Sorteando…</div>';
    }

    post('run', {
      premio_label: premio,
      cantidad: String(cant),
      excluir_ganadores_previos: exclPrev ? '1' : '',
      excluir_ids: exclIds,
    })
      .then(function (data) {
        if (!data || !data.ok) {
          if (stage) {
            stage.innerHTML =
              '<p class="rp-error">' + esc(data && data.message ? data.message : 'Error') + '</p>';
          }
          return;
        }
        var html = '<div class="rp-winners">';
        (data.ganadores || []).forEach(function (g, i) {
          html +=
            '<div class="rp-winner"><span class="rp-winner-rank">' +
            (i + 1) +
            '.</span> <strong>' +
            esc(g.nombre) +
            '</strong> <span class="rp-id">(ID ' +
            esc(g.id_usuario) +
            ')</span></div>';
        });
        html += '</div>';
        if (stage) stage.innerHTML = html;
        refreshHistorial();
      })
      .catch(function () {
        if (stage) {
          stage.innerHTML = '<p class="rp-error">Error de red.</p>';
        }
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    refreshCount();
    refreshHistorial();
    var btn = document.getElementById('rp-run');
    if (btn) btn.addEventListener('click', runSorteo);
  });
})();
