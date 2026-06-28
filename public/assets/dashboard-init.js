/**
 * Dashboard init - Carga diferida para reducir TBT.
 * Usa requestIdleCallback para ejecutar cuando el navegador esté idle.
 */
(function () {
  'use strict';

  function initDashboard() {
    // Menú de usuario: inicializar Bootstrap dropdown y fallback si no abre (p. ej. en inscripción en sitio / subrutas)
    var userDropdown = document.getElementById('user-menu-dropdown');
    var userMenuButton = document.getElementById('userMenuButton');
    var userMenuList = userDropdown && userDropdown.querySelector('.dropdown-menu');
    if (userDropdown && typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
      try {
        bootstrap.Dropdown.getOrCreateInstance(userDropdown);
      } catch (e) { /* ignorar */ }
    }
    // Fallback: si al hacer clic en el botón el menú no se abre en 150ms, abrirlo manualmente (Bootstrap puede fallar en subrutas/inscripción en sitio)
    if (userMenuButton && userMenuList) {
      userMenuButton.addEventListener('click', function () {
        var menu = userMenuList;
        setTimeout(function () {
          if (!menu.classList.contains('show')) {
            menu.classList.add('show');
            userMenuButton.setAttribute('aria-expanded', 'true');
            var closeOnClickOutside = function (ev) {
              if (!userDropdown.contains(ev.target)) {
                menu.classList.remove('show');
                userMenuButton.setAttribute('aria-expanded', 'false');
                document.removeEventListener('click', closeOnClickOutside);
              }
            };
            setTimeout(function () { document.addEventListener('click', closeOnClickOutside); }, 10);
          }
        }, 150);
      });
    }
    // Forzar navegación en cualquier enlace del menú usuario
    if (userDropdown) {
      userDropdown.addEventListener('click', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (link && link.href && link.getAttribute('href') !== '#') {
          e.preventDefault();
          e.stopPropagation();
          window.location.href = link.href;
        }
      });
    }

    // Mensajes informativos → SweetAlert (app-flash-swal.js)
    if (typeof window.AppFlashSwal !== 'undefined') {
      window.AppFlashSwal.processPageAlerts();
    }

    // Toggle sidebar
    const toggleBtn = document.getElementById('menu-toggle');
    const wrapper = document.getElementById('wrapper');
    if (toggleBtn && wrapper) {
      toggleBtn.addEventListener('click', function () {
        wrapper.classList.toggle('toggled');
        localStorage.setItem('sidebarCollapsed', wrapper.classList.contains('toggled'));
      });
      if (localStorage.getItem('sidebarCollapsed') === 'true') {
        wrapper.classList.add('toggled');
      }
    }

    // Toggle submenu - expuesto globalmente para onclick en HTML
    window.toggleSubmenu = function (submenuId, linkElement) {
      const submenu = document.getElementById(submenuId);
      const chevron = linkElement && linkElement.querySelector ? linkElement.querySelector('.submenu-icon') : null;
      if (submenu) {
        const isOpen = submenu.classList.contains('show');
        submenu.classList.toggle('show', !isOpen);
        if (chevron) {
          chevron.classList.toggle('fa-chevron-up', !isOpen);
          chevron.classList.toggle('fa-chevron-down', isOpen);
        }
      }
    };

    // Search
    var searchTimeout;
    var searchResults = null;
    var searchInput = document.getElementById('searchInput');

    window.hideSearchResults = function () {
      if (searchResults && searchResults.parentNode) {
        searchResults.parentNode.removeChild(searchResults);
      }
      searchResults = null;
    };

    function performSearch(term) {
      fetch('api/search.php?q=' + encodeURIComponent(term))
        .then(function (r) { return r.json(); })
        .then(function (data) { showSearchResults(data.results, term); })
        .catch(function (e) { console.error('Error en búsqueda:', e); });
    }

    function showSearchResults(results, query) {
      window.hideSearchResults();
      if (!results || results.length === 0) return;
      var searchBox = document.querySelector('.search-box');
      if (!searchBox) return;
      searchResults = document.createElement('div');
      searchResults.className = 'search-results';
      searchResults.innerHTML = '<div class="search-results-header"><small class="text-muted">Resultados para "' + query + '"</small><button type="button" class="btn-close" onclick="hideSearchResults()"></button></div><div class="search-results-list">' + results.map(function (r) {
        return '<a href="' + r.url + '" class="search-result-item"><div class="search-result-icon"><i class="' + r.icon + '"></i></div><div class="search-result-content"><div class="search-result-title">' + r.title + '</div><div class="search-result-subtitle">' + r.subtitle + '</div></div><div class="search-result-badge"><span class="badge bg-secondary">' + r.badge + '</span></div></a>';
      }).join('') + '</div>';
      searchBox.appendChild(searchResults);
    }

    if (searchInput) {
      searchInput.addEventListener('input', function (e) {
        var term = e.target.value.trim();
        clearTimeout(searchTimeout);
        if (term.length < 2) {
          window.hideSearchResults();
          return;
        }
        searchTimeout = setTimeout(function () { performSearch(term); }, 300);
      });
    }

    document.addEventListener('click', function (e) {
      if (!e.target.closest('.search-box')) window.hideSearchResults();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        window.hideSearchResults();
        if (searchInput) searchInput.blur();
      }
    });

    // torneo_id en sessionStorage
    var torneoId = (new URLSearchParams(window.location.search)).get('torneo_id');
    if (torneoId) sessionStorage.setItem('current_torneo_id', torneoId);

    window.toggleMobileSearch = function () {
      var sb = document.querySelector('.search-box');
      if (sb) {
        sb.classList.toggle('mobile-visible');
        var inp = sb.querySelector('input');
        if (inp && sb.classList.contains('mobile-visible')) inp.focus();
      }
    };

    if (typeof actualizarCampanitaYToast === 'function') {
      setTimeout(actualizarCampanitaYToast, 2000);
      setInterval(actualizarCampanitaYToast, 60000);
    }
  }

  if (typeof requestIdleCallback !== 'undefined') {
    requestIdleCallback(initDashboard, { timeout: 800 });
  } else {
    setTimeout(initDashboard, 0);
  }
})();
