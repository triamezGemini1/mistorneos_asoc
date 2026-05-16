/**
 * Sistema de notificaciones toast: Push del navegador + tarjeta visual en página + descarga PDF.
 * Integrado con la campanita: cuando hay notificaciones pendientes se muestra la última en toast.
 */
(function () {
    'use strict';

    var NOTIF_STORAGE_KEY = 'notif_last_shown_id';
    var NOTIF_POLL_URL = typeof window.notifAjaxUrl !== 'undefined' ? window.notifAjaxUrl : 'notificaciones_ajax.php';

    /**
     * Solicita permiso para Notificaciones Push del navegador (sistema operativo).
     */
    function solicitarPermisoPush() {
        if (typeof Notification === 'undefined') return;
        if (Notification.permission === 'default') {
            Notification.requestPermission().catch(function () {});
        }
    }

    /**
     * Cierra una tarjeta de notificación por id.
     */
    function cerrarNotificacion(id) {
        var el = document.getElementById(id);
        if (el) {
            el.classList.remove('show');
            setTimeout(function () {
                if (el.parentNode) el.parentNode.removeChild(el);
            }, 400);
        }
    }

    var JSPDF_URL = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';

    /**
     * Carga jsPDF bajo demanda (lazy load) para reducir tiempo de ejecución inicial.
     */
    function loadJsPDF(callback) {
        var JsPDFClass = (window.jspdf && window.jspdf.jsPDF) || (window.jspdf && window.jspdf.default) || window.jsPDF;
        if (typeof JsPDFClass === 'function') {
            callback();
            return;
        }
        var s = document.createElement('script');
        s.src = JSPDF_URL;
        s.async = false;
        s.onload = callback;
        s.onerror = function () { alert('No se pudo cargar la librería de PDF.'); };
        document.head.appendChild(s);
    }

    /**
     * Genera y descarga un PDF con titulo y mensaje. Carga jsPDF bajo demanda si no está presente.
     */
    function generarPDF(titulo, mensaje) {
        loadJsPDF(function () {
            var JsPDFClass = (window.jspdf && window.jspdf.jsPDF) || (window.jspdf && window.jspdf.default) || window.jsPDF;
            if (typeof JsPDFClass !== 'function') {
                alert('La generación de PDF no está disponible.');
                return;
            }
            generarPDFInner(titulo, mensaje, JsPDFClass);
        });
    }

    function generarPDFInner(titulo, mensaje, JsPDFClass) {
        var doc = new JsPDFClass();
        doc.setFontSize(22);
        doc.text(titulo, 20, 20);
        doc.setFontSize(12);
        doc.text('Fecha: ' + new Date().toLocaleString(), 20, 30);
        doc.line(20, 35, 190, 35);
        doc.setFontSize(11);
        var lineHeight = 7;
        var y = 50;
        var maxWidth = 170;
        var lines = doc.splitTextToSize(mensaje, maxWidth);
        for (var i = 0; i < lines.length; i++) {
            if (y > 270) { doc.addPage(); y = 20; }
            doc.text(lines[i], 20, y);
            y += lineHeight;
        }
        doc.save((titulo || 'Notificacion').replace(/\s+/g, '_') + '.pdf');
    }

    /**
     * Muestra una notificación: Push (si hay permiso) + tarjeta visual.
     * Si opts.datosEstructurados tiene tipo 'nueva_ronda', se renderiza la tarjeta formateada.
     */
    function enviarNotificacion(titulo, mensaje, opts) {
        opts = opts || {};
        var datos = opts.datosEstructurados;
        if (datos && datos.tipo === 'nueva_ronda') {
            enviarNotificacionNuevaRonda(datos, opts);
            return;
        }
        if (datos && datos.tipo === 'resultados_mesa') {
            enviarNotificacionResultadosMesa(datos, opts);
            return;
        }
        if (datos && datos.tipo === 'invitacion_torneo_formal') {
            enviarNotificacionInvitacionFormal(datos, opts);
            return;
        }
        if (datos && datos.tipo === 'inscripcion_torneo_confirmada') {
            enviarNotificacionInscripcionTorneo(datos, opts);
            return;
        }
        if (datos && datos.tipo === 'inscripcion_pago_validado') {
            enviarNotificacionPagoValidado(datos, opts);
            return;
        }
        if (datos && datos.tipo === 'inscripcion_recordatorio_pago') {
            enviarNotificacionRecordatorioPago(datos, opts);
            return;
        }

        var urlDestino = opts.urlDestino || '#';
        var autoClose = typeof opts.autoClose === 'number' ? opts.autoClose : 8000;
        var showPdf = opts.showPdf !== false;

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try {
                new Notification(titulo || 'Nueva notificación', { body: mensaje });
            } catch (e) {}
        }

        var container = document.getElementById('notification-container');
        if (!container) return;

        var id = 'notif-' + Date.now();
        var card = document.createElement('div');
        card.id = id;
        card.className = 'notification-card';
        card.setAttribute('data-notif-titulo', titulo || 'Nueva notificación');
        card.setAttribute('data-notif-mensaje', mensaje || '');

        var verBtn = urlDestino && urlDestino !== '#'
            ? '<a href="' + urlDestino.replace(/"/g, '&quot;') + '" class="btn-ver">Ver</a>'
            : '';
        var pdfBtn = showPdf
            ? '<button type="button" class="btn-download btn-download-pdf">Descargar PDF</button>'
            : '';

        card.innerHTML =
            '<div class="notification-card-content">' +
            '<button type="button" class="btn-close-notif" aria-label="Cerrar">&times;</button>' +
            '<h3 class="notification-card-title">' + escapeHtml(titulo || 'Nueva notificación') + '</h3>' +
            '<p class="notification-card-message">' + escapeHtml(mensaje || '') + '</p>' +
            '<div class="notification-card-actions">' + verBtn + pdfBtn + '</div>' +
            '</div>';
        container.appendChild(card);

        card.querySelector('.btn-close-notif').addEventListener('click', function () { cerrarNotificacion(id); });
        if (showPdf) {
            var pdfBtnEl = card.querySelector('.btn-download-pdf');
            if (pdfBtnEl) {
                pdfBtnEl.addEventListener('click', function () {
                    generarPDF(card.getAttribute('data-notif-titulo'), card.getAttribute('data-notif-mensaje'));
                });
            }
        }
        setTimeout(function () { card.classList.add('show'); }, 100);
        if (autoClose > 0) setTimeout(function () { cerrarNotificacion(id); }, autoClose);
    }

    function ensureAbsoluteUrl(url) {
        if (!url || url === '#') return url;
        if (url.indexOf('http') === 0) return url;
        if (typeof window.APP_BASE_URL !== 'undefined' && window.APP_BASE_URL) {
            var base = String(window.APP_BASE_URL).replace(/\/$/, '');
            return base + '/' + url.replace(/^\//, '');
        }
        var origin = window.location.origin;
        var path = window.location.pathname;
        var base = path.substring(0, path.lastIndexOf('/') + 1);
        return origin + base + url.replace(/^\//, '');
    }

    /**
     * Tarjeta formateada "Nueva Ronda": RONDA (centrada), nombre, Juega en Mesa (mismo tamaño), Pareja, stats en columnas, todo centrado, borde rojo.
     */
    function enviarNotificacionNuevaRonda(datos, opts) {
        opts = opts || {};
        var ronda = datos.ronda || '—';
        var mesa = datos.mesa || '—';
        var usuarioId = datos.usuario_id || '';
        var nombre = datos.nombre || '';
        var parejaId = datos.pareja_id || '';
        var parejaNombre = datos.pareja_nombre || datos.pareja || '—';
        var posicion = datos.posicion || '0';
        var ganados = datos.ganados || '0';
        var perdidos = datos.perdidos || '0';
        var efectividad = datos.efectividad || '0';
        var puntos = datos.puntos || '0';
        var urlResumen = (datos.url_resumen && datos.url_resumen !== '#') ? ensureAbsoluteUrl(String(datos.url_resumen)) : (typeof window.APP_BASE_URL !== 'undefined' ? window.APP_BASE_URL.replace(/\/$/, '') + '/public/index.php' : '');
        var urlClasificacion = (datos.url_clasificacion && datos.url_clasificacion !== '#') ? ensureAbsoluteUrl(String(datos.url_clasificacion)) : (typeof window.APP_BASE_URL !== 'undefined' ? window.APP_BASE_URL.replace(/\/$/, '') + '/public/index.php' : '');
        var autoClose = typeof opts.autoClose === 'number' ? opts.autoClose : 10000;

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification('Ronda ' + ronda, { body: nombre + ' · Mesa ' + mesa }); } catch (e) {}
        }

        var container = document.getElementById('notification-container');
        if (!container) return;

        var id = 'notif-' + Date.now();
        var card = document.createElement('div');
        card.id = id;
        card.className = 'notification-card notification-card-nueva-ronda';

        card.innerHTML =
            '<div class="notification-card-content">' +
            '<button type="button" class="btn-close-notif" aria-label="Cerrar">&times;</button>' +
            '<div class="notif-nueva-ronda-header text-center">RONDA ' + escapeHtml(ronda) + '</div>' +
            '<div class="notif-nueva-ronda-atleta text-center">Atleta: ' + escapeHtml(String(usuarioId)) + ' ' + escapeHtml(nombre) + '</div>' +
            '<div class="notif-nueva-ronda-mesa text-center">Juega en Mesa: ' + escapeHtml(mesa) + '</div>' +
            '<div class="notif-nueva-ronda-pareja text-center" title="Compañero de juego del atleta, inscrito con el mismo número de mesa y letra.">Pareja: ' + escapeHtml(String(parejaId)) + ' ' + escapeHtml(parejaNombre) + '</div>' +
            '<div class="notif-nueva-ronda-stats text-center">' +
            '<span class="notif-stats-label">Pos.</span><span class="notif-stats-label">Gana</span><span class="notif-stats-label">Perdi</span><span class="notif-stats-label">Efect</span><span class="notif-stats-label">Ptos</span>' +
            '<span class="notif-stats-value">' + escapeHtml(posicion) + '</span><span class="notif-stats-value">' + escapeHtml(ganados) + '</span><span class="notif-stats-value">' + escapeHtml(perdidos) + '</span><span class="notif-stats-value">' + escapeHtml(efectividad) + '</span><span class="notif-stats-value">' + escapeHtml(puntos) + '</span>' +
            '</div>' +
            '<div class="notification-card-actions"></div>' +
            '</div>';
        container.appendChild(card);

        var actions = card.querySelector('.notification-card-actions');
        var aResumen = document.createElement('a');
        aResumen.href = urlResumen || '#';
        aResumen.className = 'btn-ver';
        aResumen.innerHTML = 'Resumen jugador';
        if (!urlResumen) aResumen.setAttribute('title', 'En notificaciones reales enlaza al resumen del jugador');
        actions.appendChild(aResumen);
        var aClasif = document.createElement('a');
        aClasif.href = urlClasificacion || '#';
        aClasif.className = 'btn-clasificacion';
        aClasif.innerHTML = 'Listado de clasificación';
        if (!urlClasificacion) aClasif.setAttribute('title', 'En notificaciones reales enlaza al listado de clasificación');
        actions.appendChild(aClasif);

        card.querySelector('.btn-close-notif').addEventListener('click', function () { cerrarNotificacion(id); });
        setTimeout(function () { card.classList.add('show'); }, 100);
        if (autoClose > 0) setTimeout(function () { cerrarNotificacion(id); }, autoClose);
    }

    /**
     * Tarjeta formateada "Resultados de mesa": idéntica visualmente a nueva ronda (RONDA · MESA, atleta, ganado/perdido, resultado, sanción/tarjeta, botones).
     */
    function enviarNotificacionResultadosMesa(datos, opts) {
        opts = opts || {};
        var ronda = datos.ronda || '—';
        var mesa = datos.mesa || '—';
        var usuarioId = datos.usuario_id || '';
        var nombre = datos.nombre || '';
        var resultadoTexto = datos.resultado_texto || '—';
        var resultado1 = datos.resultado1 || '0';
        var resultado2 = datos.resultado2 || '0';
        var sancion = datos.sancion || '0';
        var tarjetaTexto = datos.tarjeta_texto || '';
        var urlResumen = (datos.url_resumen && datos.url_resumen !== '#') ? ensureAbsoluteUrl(String(datos.url_resumen)) : (typeof window.APP_BASE_URL !== 'undefined' ? window.APP_BASE_URL.replace(/\/$/, '') + '/public/index.php' : '');
        var urlClasificacion = (datos.url_clasificacion && datos.url_clasificacion !== '#') ? ensureAbsoluteUrl(String(datos.url_clasificacion)) : (typeof window.APP_BASE_URL !== 'undefined' ? window.APP_BASE_URL.replace(/\/$/, '') + '/public/index.php' : '');
        var autoClose = typeof opts.autoClose === 'number' ? opts.autoClose : 10000;

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification('Resultados Ronda ' + ronda, { body: nombre + ' · Mesa ' + mesa + ' · ' + resultado1 + ' a ' + resultado2 }); } catch (e) {}
        }

        var container = document.getElementById('notification-container');
        if (!container) return;

        var id = 'notif-' + Date.now();
        var card = document.createElement('div');
        card.id = id;
        card.className = 'notification-card notification-card-nueva-ronda';

        var lineaSancion = '';
        if ((sancion && parseInt(sancion, 10) > 0) || tarjetaTexto) {
            var partes = [];
            if (sancion && parseInt(sancion, 10) > 0) partes.push('Sancionado con ' + escapeHtml(sancion) + ' pts');
            if (tarjetaTexto) partes.push('Tarjeta ' + escapeHtml(tarjetaTexto));
            lineaSancion = '<div class="notif-nueva-ronda-pareja text-center text-warning">' + partes.join(' y ') + '.</div>';
        }

        card.innerHTML =
            '<div class="notification-card-content">' +
            '<button type="button" class="btn-close-notif" aria-label="Cerrar">&times;</button>' +
            '<div class="notif-nueva-ronda-header text-center">RESULTADOS RONDA ' + escapeHtml(ronda) + ' · MESA ' + escapeHtml(mesa) + '</div>' +
            '<div class="notif-nueva-ronda-atleta text-center">Atleta: ' + escapeHtml(String(usuarioId)) + ' ' + escapeHtml(nombre) + '</div>' +
            '<div class="notif-nueva-ronda-mesa text-center">Usted ha ' + escapeHtml(resultadoTexto) + '.</div>' +
            '<div class="notif-nueva-ronda-pareja text-center">Resultado: ' + escapeHtml(resultado1) + ' a ' + escapeHtml(resultado2) + '</div>' +
            lineaSancion +
            '<div class="notif-nueva-ronda-pareja text-center small text-muted">Si no está conforme notifique a mesa técnica.</div>' +
            '<div class="notification-card-actions"></div>' +
            '</div>';
        container.appendChild(card);

        var actions = card.querySelector('.notification-card-actions');
        var aResumen = document.createElement('a');
        aResumen.href = urlResumen || '#';
        aResumen.className = 'btn-ver';
        aResumen.innerHTML = 'Resumen jugador';
        if (!urlResumen) aResumen.setAttribute('title', 'En notificaciones reales enlaza al resumen del jugador');
        actions.appendChild(aResumen);
        var aClasif = document.createElement('a');
        aClasif.href = urlClasificacion || '#';
        aClasif.className = 'btn-clasificacion';
        aClasif.innerHTML = 'Listado de clasificación';
        if (!urlClasificacion) aClasif.setAttribute('title', 'En notificaciones reales enlaza al listado de clasificación');
        actions.appendChild(aClasif);

        card.querySelector('.btn-close-notif').addEventListener('click', function () { cerrarNotificacion(id); });
        setTimeout(function () { card.classList.add('show'); }, 100);
        if (autoClose > 0) setTimeout(function () { cerrarNotificacion(id); }, autoClose);
    }

    /**
     * Tarjeta formateada "Invitación formal a torneo": mismo formato que nueva ronda (borde rojo, centrado).
     */
    function enviarNotificacionInvitacionFormal(datos, opts) {
        opts = opts || {};
        var org = datos.organizacion_nombre || 'Invitación a Torneo';
        var tratamiento = datos.tratamiento || 'Estimado/a';
        var nombre = datos.nombre || '';
        var torneo = datos.torneo || '';
        var lugar = datos.lugar_torneo || '';
        var fecha = datos.fecha_torneo || '';
        var urlInscripcion = (datos.url_inscripcion && datos.url_inscripcion !== '#') ? ensureAbsoluteUrl(String(datos.url_inscripcion)) : (typeof window.APP_BASE_URL !== 'undefined' ? window.APP_BASE_URL.replace(/\/$/, '') + '/public/index.php' : '');
        var autoClose = typeof opts.autoClose === 'number' ? opts.autoClose : 10000;

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification(org, { body: tratamiento + ' ' + nombre + ': ' + torneo }); } catch (e) {}
        }

        var container = document.getElementById('notification-container');
        if (!container) return;

        var id = 'notif-' + Date.now();
        var card = document.createElement('div');
        card.id = id;
        card.className = 'notification-card notification-card-invitacion-formal';

        card.innerHTML =
            '<div class="notification-card-content">' +
            '<button type="button" class="btn-close-notif" aria-label="Cerrar">&times;</button>' +
            '<div class="notif-invitacion-org text-center">' + escapeHtml(org) + '</div>' +
            '<div class="notif-invitacion-saludo text-center">' + escapeHtml(tratamiento) + ' ' + escapeHtml(nombre) + '</div>' +
            '<div class="notif-invitacion-torneo text-center">' + escapeHtml(torneo) + '</div>' +
            '<div class="notif-invitacion-lugar text-center">' + escapeHtml(lugar) + ' · ' + escapeHtml(fecha) + '</div>' +
            '<div class="notification-card-actions"></div>' +
            '</div>';
        container.appendChild(card);

        var actions = card.querySelector('.notification-card-actions');
        var aInsc = document.createElement('a');
        aInsc.href = urlInscripcion || '#';
        aInsc.className = 'btn-ver';
        aInsc.innerHTML = 'Inscribirse en línea';
        if (!urlInscripcion) aInsc.setAttribute('title', 'En notificaciones reales enlaza al formulario de inscripción');
        actions.appendChild(aInsc);

        card.querySelector('.btn-close-notif').addEventListener('click', function () { cerrarNotificacion(id); });
        setTimeout(function () { card.classList.add('show'); }, 100);
        if (autoClose > 0) setTimeout(function () { cerrarNotificacion(id); }, autoClose);
    }

    /**
     * Tarjeta "Inscripción confirmada" (landing / inscripción en sitio por asociación).
     */
    function enviarNotificacionInscripcionTorneo(datos, opts) {
        opts = opts || {};
        var org = datos.organizacion_nombre || 'FVD';
        var nombre = datos.nombre || '';
        var torneo = datos.torneo || '';
        var lugar = datos.lugar_torneo || '';
        var fecha = datos.fecha_torneo || '';
        var asoc = datos.asociacion || '';
        var costo = datos.costo || '';
        var urlPago = (datos.url_pago && datos.url_pago !== '#') ? ensureAbsoluteUrl(String(datos.url_pago)) : '';
        var autoClose = typeof opts.autoClose === 'number' ? opts.autoClose : 12000;

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification('Inscripción exitosa', { body: nombre + ' · ' + torneo }); } catch (e) {}
        }

        var container = document.getElementById('notification-container');
        if (!container) return;

        var id = 'notif-' + Date.now();
        var card = document.createElement('div');
        card.id = id;
        card.className = 'notification-card notification-card-inscripcion-torneo';

        var lineaAsoc = asoc ? '<div class="notif-invitacion-lugar text-center">Asociación: ' + escapeHtml(asoc) + '</div>' : '';
        var lineaCosto = costo ? '<div class="notif-invitacion-lugar text-center fw-bold">Costo: $' + escapeHtml(costo) + '</div>' : '';

        card.innerHTML =
            '<div class="notification-card-content">' +
            '<button type="button" class="btn-close-notif" aria-label="Cerrar">&times;</button>' +
            '<div class="notif-invitacion-org text-center">✅ Inscripción exitosa</div>' +
            '<div class="notif-invitacion-saludo text-center">Hola ' + escapeHtml(nombre) + '</div>' +
            '<div class="notif-invitacion-torneo text-center">' + escapeHtml(torneo) + '</div>' +
            '<div class="notif-invitacion-lugar text-center">' + escapeHtml(lugar) + (lugar && fecha ? ' · ' : '') + escapeHtml(fecha) + '</div>' +
            lineaAsoc + lineaCosto +
            '<div class="notification-card-actions"></div>' +
            '<div class="notif-invitacion-org text-center small text-muted mt-1">' + escapeHtml(org) + '</div>' +
            '</div>';
        container.appendChild(card);

        var actions = card.querySelector('.notification-card-actions');
        if (urlPago) {
            var aPago = document.createElement('a');
            aPago.href = urlPago;
            aPago.className = 'btn-ver';
            aPago.innerHTML = 'Reportar pago';
            actions.appendChild(aPago);
        }

        card.querySelector('.btn-close-notif').addEventListener('click', function () { cerrarNotificacion(id); });
        setTimeout(function () { card.classList.add('show'); }, 100);
        if (autoClose > 0) setTimeout(function () { cerrarNotificacion(id); }, autoClose);
    }

    /**
     * Tarjeta "Pago validado" tras confirmación administrativa.
     */
    function enviarNotificacionRecordatorioPago(datos, opts) {
        opts = opts || {};
        var org = datos.organizacion_nombre || 'FVD';
        var nombre = datos.nombre || '';
        var torneo = datos.torneo || '';
        var lugar = datos.lugar_torneo || '';
        var fecha = datos.fecha_torneo || '';
        var fechaLimite = datos.fecha_limite_pago || '';
        var asoc = datos.asociacion || '';
        var costo = datos.costo || '';
        var nota = datos.nota || '';
        var autoClose = typeof opts.autoClose === 'number' ? opts.autoClose : 14000;

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification('Recordatorio de pago', { body: nombre + ' · ' + torneo }); } catch (e) {}
        }

        var container = document.getElementById('notification-container');
        if (!container) return;

        var id = 'notif-' + Date.now();
        var card = document.createElement('div');
        card.id = id;
        card.className = 'notification-card notification-card-inscripcion-torneo';

        var lineaAsoc = asoc ? '<div class="notif-invitacion-lugar text-center">Asociación: ' + escapeHtml(asoc) + '</div>' : '';
        var lineaCosto = costo ? '<div class="notif-invitacion-lugar text-center fw-bold text-warning">Pendiente: $' + escapeHtml(costo) + '</div>' : '';
        var lineaLimite = fechaLimite ? '<div class="notif-invitacion-lugar text-center text-danger">Fecha límite de pago: ' + escapeHtml(fechaLimite) + '</div>' : '';

        card.innerHTML =
            '<div class="notification-card-content">' +
            '<button type="button" class="btn-close-notif" aria-label="Cerrar">&times;</button>' +
            '<div class="notif-invitacion-org text-center text-warning fw-bold">⏰ Recordatorio de pago</div>' +
            '<div class="notif-invitacion-saludo text-center">Hola ' + escapeHtml(nombre) + '</div>' +
            '<div class="notif-invitacion-torneo text-center">' + escapeHtml(torneo) + '</div>' +
            '<div class="notif-invitacion-lugar text-center">' + escapeHtml(lugar) + (lugar && fecha ? ' · ' : '') + escapeHtml(fecha) + '</div>' +
            lineaAsoc + lineaCosto + lineaLimite +
            (nota ? '<p class="text-center small mb-0 mt-2">' + escapeHtml(nota) + '</p>' : '') +
            '<div class="notif-invitacion-org text-center small text-muted mt-1">' + escapeHtml(org) + '</div>' +
            '</div>';
        container.appendChild(card);
        card.querySelector('.btn-close-notif').addEventListener('click', function () { cerrarNotificacion(id); });
        setTimeout(function () { card.classList.add('show'); }, 100);
        if (autoClose > 0) setTimeout(function () { cerrarNotificacion(id); }, autoClose);
    }

    function enviarNotificacionPagoValidado(datos, opts) {
        opts = opts || {};
        var org = datos.organizacion_nombre || 'FVD';
        var nombre = datos.nombre || '';
        var torneo = datos.torneo || '';
        var lugar = datos.lugar_torneo || '';
        var fecha = datos.fecha_torneo || '';
        var asoc = datos.asociacion || '';
        var monto = datos.monto || '';
        var autoClose = typeof opts.autoClose === 'number' ? opts.autoClose : 12000;

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification('Pago validado', { body: nombre + ' · ' + torneo }); } catch (e) {}
        }

        var container = document.getElementById('notification-container');
        if (!container) return;

        var id = 'notif-' + Date.now();
        var card = document.createElement('div');
        card.id = id;
        card.className = 'notification-card notification-card-inscripcion-torneo';

        var lineaAsoc = asoc ? '<motion.div class="notif-invitacion-lugar text-center">Asociación: ' + escapeHtml(asoc) + '</motion.div>' : ''.replace(/motion\./g, '');
        var lineaMonto = monto ? '<div class="notif-invitacion-lugar text-center fw-bold text-success">Monto validado: $' + escapeHtml(monto) + '</div>' : '';

        card.innerHTML =
            '<div class="notification-card-content">' +
            '<button type="button" class="btn-close-notif" aria-label="Cerrar">&times;</button>' +
            '<div class="notif-invitacion-org text-center text-success fw-bold">✅ Pago validado</div>' +
            '<div class="notif-invitacion-saludo text-center">Hola ' + escapeHtml(nombre) + '</div>' +
            '<div class="notif-invitacion-torneo text-center">' + escapeHtml(torneo) + '</div>' +
            '<div class="notif-invitacion-lugar text-center">' + escapeHtml(lugar) + (lugar && fecha ? ' · ' : '') + escapeHtml(fecha) + '</div>' +
            lineaAsoc + lineaMonto +
            '<p class="text-center small mb-0 mt-2">Su inscripción quedó <strong>confirmada</strong>.</p>' +
            '<div class="notif-invitacion-org text-center small text-muted mt-1">' + escapeHtml(org) + '</div>' +
            '</div>';
        container.appendChild(card);
        card.querySelector('.btn-close-notif').addEventListener('click', function () { cerrarNotificacion(id); });
        setTimeout(function () { card.classList.add('show'); }, 100);
        if (autoClose > 0) setTimeout(function () { cerrarNotificacion(id); }, autoClose);
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    var NOTIF_FETCH_TIMEOUT_MS = 8000;

    /**
     * Actualiza badge de la campanita y, si hay nueva notificación pendiente, muestra toast.
     * Espera respuesta JSON: { count: number, latest: { id, titulo, mensaje, url_destino } | null }
     * Usa timeout para evitar que una petición colgada mantenga el indicador de carga del navegador.
     */
    function actualizarCampanitaYToast() {
        var badge = document.getElementById('campana-badge');
        var url = NOTIF_POLL_URL + (NOTIF_POLL_URL.indexOf('?') >= 0 ? '&' : '?') + 'format=json';
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId = controller ? window.setTimeout(function () { controller.abort(); }, NOTIF_FETCH_TIMEOUT_MS) : null;
        var opts = {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        };
        if (controller) opts.signal = controller.signal;
        fetch(url, opts)
            .then(function (res) {
                if (timeoutId) window.clearTimeout(timeoutId);
                return res.json();
            })
            .then(function (data) {
                var count = parseInt(data.count, 10) || 0;
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.style.display = 'block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
                var latest = data.latest;
                if (latest && latest.id) {
                    var lastShown = null;
                    try {
                        lastShown = sessionStorage.getItem(NOTIF_STORAGE_KEY);
                    } catch (e) {}
                    if (String(latest.id) !== lastShown) {
                        var opts = { urlDestino: latest.url_destino || '#', showPdf: true };
                        if (latest.datos_estructurados) opts.datosEstructurados = latest.datos_estructurados;
                        enviarNotificacion(
                            latest.titulo || 'Nueva notificación',
                            latest.mensaje || '',
                            opts
                        );
                        try {
                            sessionStorage.setItem(NOTIF_STORAGE_KEY, String(latest.id));
                        } catch (e) {}
                    }
                }
            })
            .catch(function () {
                if (timeoutId) window.clearTimeout(timeoutId);
                var ctrl2 = typeof AbortController !== 'undefined' ? new AbortController() : null;
                var tid2 = ctrl2 ? window.setTimeout(function () { ctrl2.abort(); }, NOTIF_FETCH_TIMEOUT_MS) : null;
                var opts2 = { credentials: 'same-origin', cache: 'no-store' };
                if (ctrl2) opts2.signal = ctrl2.signal;
                fetch(NOTIF_POLL_URL, opts2)
                    .then(function (r) {
                        if (tid2) window.clearTimeout(tid2);
                        return r.text();
                    })
                    .then(function (total) {
                        if (badge) {
                            var n = parseInt(total, 10) || 0;
                            if (n > 0) {
                                badge.textContent = n > 99 ? '99+' : n;
                                badge.style.display = 'block';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    })
                    .catch(function () {
                        if (tid2) window.clearTimeout(tid2);
                    });
            });
    }

    // Exponer para onclick desde HTML y para uso global
    window.solicitarPermisoPush = solicitarPermisoPush;
    window.enviarNotificacion = enviarNotificacion;
    window.notifToastCerrar = cerrarNotificacion;
    window.notifToastGenerarPDF = generarPDF;
    window.actualizarCampanitaYToast = actualizarCampanitaYToast;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            solicitarPermisoPush();
        });
    } else {
        solicitarPermisoPush();
    }
})();
