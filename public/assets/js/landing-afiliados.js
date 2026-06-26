(function () {
  'use strict';

  const { createApp, ref, computed, onMounted, watch } = Vue;

  function parseRoute() {
    const hash = (window.location.hash || '#/').replace(/^#/, '');
    const parts = hash.split('/').filter(Boolean);
    if (parts.length === 0 || parts[0] === 'list') {
      return { view: 'list' };
    }
    if (parts[0] === 'a' && parts[1]) {
      const orgId = parseInt(parts[1], 10);
      if (parts[2] === 'torneos') {
        return { view: 'torneos', orgId };
      }
      if (parts[2] === 'clubes') {
        return { view: 'clubes', orgId };
      }
      if (parts[2] === 'club' && parts[3]) {
        const clubId = parseInt(parts[3], 10);
        if (parts[4] === 'u' && parts[5]) {
          return { view: 'afiliado', orgId, clubId, userId: parseInt(parts[5], 10) };
        }
        return { view: 'club', orgId, clubId };
      }
      if (parts[2] === 't' && parts[3]) {
        return { view: 'torneo', orgId, torneoId: parseInt(parts[3], 10) };
      }
      return { view: 'hub', orgId };
    }
    return { view: 'list' };
  }

  function navigate(route) {
    if (route.view === 'list') {
      window.location.hash = '#/';
      return;
    }
    if (route.view === 'hub') {
      window.location.hash = '#/a/' + route.orgId;
      return;
    }
    if (route.view === 'torneos') {
      window.location.hash = '#/a/' + route.orgId + '/torneos';
      return;
    }
    if (route.view === 'clubes') {
      window.location.hash = '#/a/' + route.orgId + '/clubes';
      return;
    }
    if (route.view === 'club') {
      window.location.hash = '#/a/' + route.orgId + '/club/' + route.clubId;
      return;
    }
    if (route.view === 'afiliado') {
      window.location.hash = '#/a/' + route.orgId + '/club/' + route.clubId + '/u/' + route.userId;
      return;
    }
    if (route.view === 'torneo') {
      window.location.hash = '#/a/' + route.orgId + '/t/' + route.torneoId;
    }
  }

  function formatFecha(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString('es-VE', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  createApp({
    setup() {
      const config = window.LANDING_AFILIADOS_CONFIG || {};
      const apiUrl = config.apiUrl || '';
      const loading = ref(true);
      const error = ref('');
      const route = ref(parseRoute());
      const brand = ref(config.brand || {});
      const baseUrl = ref(config.baseUrl || '');
      const landingUrl = ref(
        config.landingUrl || ((config.baseUrl || '') + 'landing-spa.php#asociaciones-afiliadas')
      );
      const afiliados = ref([]);
      const hub = ref(null);
      const torneos = ref({ realizados: [], en_proceso: [] });
      const torneo = ref(null);
      const torneoUrls = ref({});
      const clubes = ref([]);
      const clubDetalle = ref(null);
      const afiliadoDetalle = ref(null);
      const actionMsg = ref('');
      const access = ref(null);

      const isInvitado = computed(() => (access.value?.tipo || 'invitado') === 'invitado');
      const isAdmin = computed(() => !!access.value?.es_admin);
      const loginUrl = computed(() => {
        if (access.value?.urls?.login) {
          return access.value.urls.login;
        }
        const orgId = route.value.orgId || hub.value?.afiliado?.id || 0;
        const ret = 'landing-afiliados.php' + (orgId ? '#/a/' + orgId : '');
        return baseUrl.value + 'login.php?return_url=' + encodeURIComponent(ret);
      });

      function applyAccess(data) {
        if (data?.access) {
          access.value = data.access;
        }
      }

      function handleAuthRedirect(data) {
        if (data?.redirect) {
          window.location.href = data.redirect;
          return true;
        }
        return false;
      }

      const pageTitle = computed(() => {
        if (route.value.view === 'hub' && hub.value?.afiliado) {
          return hub.value.afiliado.nombre;
        }
        if (route.value.view === 'torneos' && hub.value?.afiliado) {
          return 'Torneos — ' + hub.value.afiliado.nombre;
        }
        if (route.value.view === 'clubes' && hub.value?.afiliado) {
          return 'Clubes — ' + hub.value.afiliado.nombre;
        }
        if (route.value.view === 'club' && clubDetalle.value?.club) {
          return clubDetalle.value.club.nombre;
        }
        if (route.value.view === 'afiliado' && afiliadoDetalle.value?.afiliado) {
          return afiliadoDetalle.value.afiliado.nombre;
        }
        if (route.value.view === 'torneo' && torneo.value) {
          return torneo.value.nombre || 'Detalle del torneo';
        }
        return brand.value.name || 'MisTorneos ASOC';
      });

      async function fetchJson(params) {
        const qs = new URLSearchParams(params).toString();
        const res = await fetch(apiUrl + (qs ? '?' + qs : ''), { credentials: 'same-origin' });
        const data = await res.json();
        if (handleAuthRedirect(data)) {
          return data;
        }
        if (!data.success) {
          throw new Error(data.error || 'Error al cargar datos');
        }
        applyAccess(data);
        return data;
      }

      async function postJson(body) {
        const res = await fetch(apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(body),
        });
        const data = await res.json();
        if (handleAuthRedirect(data)) {
          return data;
        }
        if (!data.success) {
          throw new Error(data.error || 'Error en la operación');
        }
        return data;
      }

      async function loadCurrentView() {
        loading.value = true;
        error.value = '';
        actionMsg.value = '';
        try {
          const r = route.value;
          if (r.view === 'list') {
            const data = await fetchJson({ action: 'list' });
            brand.value = data.brand || brand.value;
            baseUrl.value = data.base_url || baseUrl.value;
            afiliados.value = data.afiliados || [];
          } else if (r.view === 'hub') {
            const data = await fetchJson({ action: 'hub', org_id: r.orgId });
            hub.value = data;
            brand.value = data.brand || brand.value;
          } else if (r.view === 'torneos') {
            const [hubData, torData] = await Promise.all([
              fetchJson({ action: 'hub', org_id: r.orgId }),
              fetchJson({ action: 'torneos', org_id: r.orgId }),
            ]);
            hub.value = hubData;
            torneos.value = torData.torneos || { realizados: [], en_proceso: [] };
          } else if (r.view === 'clubes') {
            const [hubData, clubData] = await Promise.all([
              fetchJson({ action: 'hub', org_id: r.orgId }),
              fetchJson({ action: 'clubes', org_id: r.orgId }),
            ]);
            hub.value = hubData;
            clubes.value = clubData.clubes || [];
          } else if (r.view === 'club') {
            const [hubData, clubData] = await Promise.all([
              fetchJson({ action: 'hub', org_id: r.orgId }),
              fetchJson({ action: 'club', org_id: r.orgId, club_id: r.clubId }),
            ]);
            hub.value = hubData;
            clubDetalle.value = clubData;
          } else if (r.view === 'afiliado') {
            const [hubData, afData] = await Promise.all([
              fetchJson({ action: 'hub', org_id: r.orgId }),
              fetchJson({
                action: 'afiliado',
                org_id: r.orgId,
                club_id: r.clubId,
                user_id: r.userId,
              }),
            ]);
            hub.value = hubData;
            afiliadoDetalle.value = afData;
          } else if (r.view === 'torneo') {
            const data = await fetchJson({
              action: 'torneo',
              org_id: r.orgId,
              torneo_id: r.torneoId,
            });
            torneo.value = data.torneo;
            torneoUrls.value = data.urls || {};
            if (!hub.value || hub.value.afiliado?.id !== r.orgId) {
              hub.value = await fetchJson({ action: 'hub', org_id: r.orgId });
            }
          }
        } catch (e) {
          error.value = e.message || 'No se pudo cargar la información';
        } finally {
          loading.value = false;
        }
      }

      function goList() {
        navigate({ view: 'list' });
      }
      function goLanding() {
        window.location.href = landingUrl.value;
      }
      function goHub(orgId) {
        navigate({ view: 'hub', orgId });
      }
      function goTorneos(orgId) {
        navigate({ view: 'torneos', orgId });
      }
      function goClubes(orgId) {
        navigate({ view: 'clubes', orgId });
      }
      function goClub(orgId, clubId) {
        navigate({ view: 'club', orgId, clubId });
      }
      function goAfiliado(orgId, clubId, userId) {
        navigate({ view: 'afiliado', orgId, clubId, userId });
      }
      function goTorneo(orgId, torneoId) {
        navigate({ view: 'torneo', orgId, torneoId });
      }

      async function toggleAfiliado(a) {
        if (!clubDetalle.value?.puede_gestionar) {
          return;
        }
        const activar = a.estatus !== 'activo';
        const msg = activar ? '¿Activar este afiliado?' : '¿Desactivar este afiliado?';
        if (!window.confirm(msg)) {
          return;
        }
        try {
          actionMsg.value = '';
          const data = await postJson({
            action: 'toggle_afiliado',
            org_id: route.value.orgId,
            club_id: route.value.clubId,
            user_id: a.id,
          });
          a.estatus = data.estatus;
          a.estatus_label = data.estatus_label;
          actionMsg.value = 'Estatus actualizado correctamente.';
        } catch (e) {
          actionMsg.value = e.message || 'No se pudo cambiar el estatus';
        }
      }

      onMounted(() => {
        if (!window.location.hash) {
          window.location.hash = '#/';
        }
        window.addEventListener('hashchange', () => {
          route.value = parseRoute();
        });
        route.value = parseRoute();
      });

      watch(route, () => loadCurrentView(), { deep: true });

      return {
        loading,
        error,
        route,
        brand,
        baseUrl,
        landingUrl,
        afiliados,
        hub,
        torneos,
        torneo,
        torneoUrls,
        clubes,
        clubDetalle,
        afiliadoDetalle,
        actionMsg,
        access,
        isInvitado,
        isAdmin,
        loginUrl,
        pageTitle,
        formatFecha,
        goList,
        goLanding,
        goHub,
        goTorneos,
        goClubes,
        goClub,
        goAfiliado,
        goTorneo,
        toggleAfiliado,
        loadCurrentView,
      };
    },
  }).mount('#landing-afiliados-app');
})();
