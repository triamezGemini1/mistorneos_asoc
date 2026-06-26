<?php
/**
 * Landing ASOC — acceso por asociación afiliada.
 * URL: .../public/landing-afiliados.php (también vía landing-spa.php con segmento asoc).
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/Branding.php';

$base_url = rtrim(AppHelpers::getPublicUrl(), '/') . '/';
$api_url = $base_url . 'api/landing_afiliados.php';
$landing_url = $base_url . 'landing-spa.php#asociaciones-afiliadas';
$brand = [
    'name' => Branding::siteName(),
    'tagline' => Branding::tagline(),
    'logo_url' => Branding::logoUrl(),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="index, follow">
    <title><?= htmlspecialchars(Branding::landingMetaTitle()) ?></title>
    <meta name="description" content="<?= htmlspecialchars(Branding::metaDescription()) ?>">
    <?php include __DIR__ . '/includes/partials/brand_theme.php'; ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($base_url) ?>assets/css/landing-afiliados.css">
</head>
<body>
<div id="landing-afiliados-app" class="landing-afiliados">
    <header class="landing-afiliados__header">
        <div class="landing-afiliados__header-inner">
            <a :href="landingUrl" class="landing-afiliados__brand">
                <img :src="brand.logo_url" :alt="brand.name">
                <div>
                    <strong>{{ brand.name }}</strong>
                    <div style="font-size:0.8rem;opacity:0.9">Gestor de torneos para asociaciones</div>
                </div>
            </a>
            <div class="d-flex gap-2" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
                <a v-if="route.view !== 'list'" href="#" class="landing-afiliados__back" @click.prevent="goList">
                    <i class="fas fa-arrow-left me-1"></i>Asociaciones
                </a>
                <a href="#" class="landing-afiliados__back" @click.prevent="goLanding">
                    <i class="fas fa-home me-1"></i>Inicio
                </a>
                <a v-if="access?.es_usuario" :href="access.urls.perfil" class="landing-afiliados__btn landing-afiliados__btn--outline" style="border-color:#fff;color:#fff">
                    <i class="fas fa-user"></i> Mi perfil
                </a>
                <a v-else-if="access?.es_admin" :href="access.urls.admin" class="landing-afiliados__btn landing-afiliados__btn--outline" style="border-color:#fff;color:#fff">
                    <i class="fas fa-cog"></i> Panel
                </a>
                <a v-else :href="loginUrl" class="landing-afiliados__btn landing-afiliados__btn--outline" style="border-color:#fff;color:#fff">
                    <i class="fas fa-sign-in-alt"></i> Acceso admin
                </a>
            </div>
        </div>
    </header>

    <main class="landing-afiliados__main">
        <div v-if="loading" class="landing-afiliados__loading">
            <i class="fas fa-spinner fa-spin fa-2x" style="color:#0e86a9"></i>
            <p class="mt-3">Cargando…</p>
        </div>
        <div v-else-if="error" class="landing-afiliados__error">
            <p class="text-danger">{{ error }}</p>
            <button type="button" class="landing-afiliados__btn" @click="loadCurrentView">Reintentar</button>
        </div>

        <!-- Lista de afiliados -->
        <template v-else-if="route.view === 'list'">
            <section class="landing-afiliados__intro">
                <h1 style="font-size:1.35rem;margin:0 0 0.35rem;color:#0e86a9">Asociaciones afiliadas</h1>
                <p style="margin:0;color:#475569">{{ isInvitado ? 'Seleccione su asociación para ver torneos, resultados y ranking del estado.' : 'Seleccione su asociación para gestionar información, torneos y afiliados.' }}</p>
                <div class="landing-afiliados__btn-row">
                    <a href="#" class="landing-afiliados__btn landing-afiliados__btn--outline" @click.prevent="goLanding">
                        <i class="fas fa-arrow-left"></i> Volver al inicio
                    </a>
                </div>
            </section>
            <div v-if="!afiliados.length" class="landing-afiliados__card">
                <p>No hay asociaciones activas publicadas.</p>
            </div>
            <div v-else class="landing-afiliados__grid">
                <a
                    v-for="(a, idx) in afiliados"
                    :key="a.id"
                    href="#"
                    class="landing-afiliados__card landing-afiliados__card--clickable landing-afiliados__card--afiliado"
                    @click.prevent="goHub(a.id)"
                >
                    <div class="landing-afiliados__logo-wrap">
                        <img v-if="a.logo_url" :src="a.logo_url" :alt="a.nombre" class="landing-afiliados__logo-md">
                        <i v-else class="fas fa-building" style="font-size:2rem;color:#FC619E"></i>
                    </div>
                    <div class="landing-afiliados__card-title">{{ a.nombre }}</div>
                    <div v-if="a.entidad_nombre" class="landing-afiliados__badge mb-2">{{ a.entidad_nombre }}</div>
                    <div class="landing-afiliados__stats landing-afiliados__stats--center">
                        <span><i class="fas fa-users me-1"></i>{{ a.afiliados ?? 0 }} afiliados</span>
                        <span><i class="fas fa-building me-1"></i>{{ a.clubes ?? 0 }} clubes</span>
                        <span><i class="fas fa-trophy me-1"></i>{{ a.torneos ?? 0 }} torneos</span>
                    </div>
                    <p class="landing-afiliados__card-desc" style="margin:0.5rem 0 0">Torneos y ranking del estado</p>
                </a>
            </div>
        </template>

        <!-- Hub del afiliado -->
        <template v-else-if="route.view === 'hub' && hub">
            <!-- Vista pública (invitados) -->
            <template v-if="hub.modo === 'publico'">
                <section class="landing-afiliados__intro landing-afiliados__intro--hub landing-afiliados__hub-block">
                    <div class="landing-afiliados__hub-head">
                        <div class="landing-afiliados__logo-wrap landing-afiliados__logo-wrap--hero">
                            <img v-if="hub.afiliado.logo_url" :src="hub.afiliado.logo_url" :alt="hub.afiliado.nombre" class="landing-afiliados__logo-md">
                            <i v-else class="fas fa-building" style="font-size:2.5rem;color:#FC619E"></i>
                        </div>
                        <div class="landing-afiliados__hub-head-text">
                            <h1 class="landing-afiliados__hub-title">{{ hub.afiliado.nombre }}</h1>
                            <p class="landing-afiliados__hub-subtitle">{{ hub.afiliado.entidad_nombre || 'Asociación territorial' }}</p>
                        </div>
                    </div>
                    <div class="landing-afiliados__btn-row landing-afiliados__btn-row--hub">
                        <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goList">
                            <i class="fas fa-arrow-left"></i> Asociaciones
                        </button>
                        <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goLanding">
                            <i class="fas fa-home"></i> Inicio
                        </button>
                    </div>
                </section>

                <h2 class="landing-afiliados__section-title">Torneos realizados</h2>
                <div v-if="!hub.torneos_realizados?.length" class="landing-afiliados__card landing-afiliados__hub-block">
                    <p>No hay torneos finalizados publicados para esta asociación.</p>
                </div>
                <div v-else class="landing-afiliados__torneo-list landing-afiliados__hub-block">
                    <article
                        v-for="t in hub.torneos_realizados"
                        :key="t.id"
                        class="landing-afiliados__card landing-afiliados__card--torneo-compact"
                    >
                        <div class="landing-afiliados__torneo-compact">
                            <img
                                v-if="t.afiche_url || t.logo_url"
                                :src="t.afiche_url || t.logo_url"
                                alt=""
                                class="landing-afiliados__logo-xs landing-afiliados__torneo-compact__logo"
                            >
                            <i v-else class="fas fa-trophy landing-afiliados__torneo-compact__logo landing-afiliados__torneo-compact__logo--icon" aria-hidden="true"></i>
                            <h3 class="landing-afiliados__torneo-compact__title" :title="t.nombre">{{ t.nombre }}</h3>
                            <p class="landing-afiliados__torneo-compact__meta">
                                <span><i class="fas fa-calendar"></i>{{ formatFecha(t.fechator) }}</span>
                                <span><i class="fas fa-map-marker-alt"></i>{{ t.lugar || '—' }}</span>
                                <span><i class="fas fa-users"></i>{{ t.total_inscritos }}</span>
                            </p>
                            <a :href="t.urls.resultados" class="landing-afiliados__btn landing-afiliados__btn--xs landing-afiliados__torneo-compact__action">
                                <i class="fas fa-chart-bar"></i><span class="landing-afiliados__torneo-compact__action-text">Resultados</span>
                            </a>
                        </div>
                    </article>
                </div>
                <div v-if="hub.urls?.eventos" class="landing-afiliados__btn-row landing-afiliados__hub-block">
                    <a :href="hub.urls.eventos" class="landing-afiliados__btn landing-afiliados__btn--outline">
                        <i class="fas fa-list"></i> Torneos realizados
                    </a>
                </div>

                <article class="landing-afiliados__card landing-afiliados__card--full landing-afiliados__hub-block">
                    <div class="landing-afiliados__card-title">
                        <i class="fas fa-chart-line me-2"></i>Ranking del estado
                        <span v-if="hub.afiliado.entidad_nombre" class="landing-afiliados__badge ms-2">{{ hub.afiliado.entidad_nombre }}</span>
                    </div>
                    <p class="landing-afiliados__card-desc">Clasificación de atletas del estado según rendimiento en torneos de esta asociación.</p>
                    <a :href="hub.urls.ranking_estado || hub.urls.ranking" class="landing-afiliados__btn">
                        <i class="fas fa-medal"></i> Ver ranking del estado
                    </a>
                </article>
            </template>

            <!-- Vista administración -->
            <template v-else>
            <section class="landing-afiliados__intro landing-afiliados__intro--hub landing-afiliados__hub-block">
                <div class="landing-afiliados__hub-head">
                    <div class="landing-afiliados__logo-wrap landing-afiliados__logo-wrap--hero">
                        <img v-if="hub.afiliado.logo_url" :src="hub.afiliado.logo_url" :alt="hub.afiliado.nombre" class="landing-afiliados__logo-md">
                        <i v-else class="fas fa-building" style="font-size:2.5rem;color:#FC619E"></i>
                    </div>
                    <div class="landing-afiliados__hub-head-text">
                        <h1 class="landing-afiliados__hub-title">{{ hub.afiliado.nombre }}</h1>
                        <p class="landing-afiliados__hub-subtitle">{{ hub.afiliado.entidad_nombre || 'Asociación territorial' }}</p>
                    </div>
                </div>
                <div class="landing-afiliados__btn-row landing-afiliados__btn-row--hub">
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goList">
                        <i class="fas fa-arrow-left"></i> Asociaciones
                    </button>
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goLanding">
                        <i class="fas fa-home"></i> Inicio
                    </button>
                </div>
            </section>

            <article class="landing-afiliados__card landing-afiliados__card--full landing-afiliados__card--info landing-afiliados__hub-block">
                <div class="landing-afiliados__card-title"><i class="fas fa-id-card me-2"></i>Información del afiliado</div>
                <dl class="landing-afiliados__detail-grid landing-afiliados__detail-grid--wide">
                    <div><dt>Responsable</dt><dd>{{ hub.afiliado.responsable || '—' }}</dd></div>
                    <div><dt>Teléfono</dt><dd>{{ hub.afiliado.telefono || '—' }}</dd></div>
                    <div><dt>Email</dt><dd>{{ hub.afiliado.email || '—' }}</dd></div>
                    <div><dt>Dirección</dt><dd>{{ hub.afiliado.direccion || '—' }}</dd></div>
                    <div><dt>Clubes</dt><dd>{{ hub.afiliado.clubes }}</dd></div>
                    <div><dt>Afiliados</dt><dd>{{ hub.afiliado.afiliados }}</dd></div>
                </dl>
            </article>

            <div class="landing-afiliados__hub-actions">
                <article class="landing-afiliados__card landing-afiliados__card--action">
                    <div class="landing-afiliados__card-title"><i class="fas fa-users me-2"></i>Clubes</div>
                    <p class="landing-afiliados__card-desc">
                        {{ hub.afiliado.clubes }} club(es) registrados. Consulte afiliados por club o solicite su afiliación.
                    </p>
                    <div class="landing-afiliados__stats mb-2">
                        <span class="landing-afiliados__badge">{{ hub.afiliado.afiliados }} afiliados</span>
                    </div>
                    <button type="button" class="landing-afiliados__btn" @click="goClubes(hub.afiliado.id)">
                        <i class="fas fa-list"></i> Ver clubes
                    </button>
                </article>

                <article class="landing-afiliados__card landing-afiliados__card--action">
                    <div class="landing-afiliados__card-title"><i class="fas fa-trophy me-2"></i>Torneos</div>
                    <div class="landing-afiliados__stats mb-2">
                        <span class="landing-afiliados__badge">{{ hub.torneos_resumen.realizados }} realizados</span>
                        <span class="landing-afiliados__badge">{{ hub.torneos_resumen.en_proceso }} en proceso</span>
                        <span class="landing-afiliados__badge">{{ hub.torneos_resumen.por_realizar }} por realizar</span>
                    </div>
                    <button type="button" class="landing-afiliados__btn" @click="goTorneos(hub.afiliado.id)">
                        <i class="fas fa-list"></i> Ver torneos
                    </button>
                </article>

                <article class="landing-afiliados__card landing-afiliados__card--action">
                    <div class="landing-afiliados__card-title"><i class="fas fa-chart-line me-2"></i>Ranking</div>
                    <p class="landing-afiliados__card-desc">
                        Clasificación de atletas de esta asociación por rendimiento acumulado.
                    </p>
                    <a :href="hub.urls.ranking" class="landing-afiliados__btn">
                        <i class="fas fa-medal"></i> Ver ranking
                    </a>
                </article>
            </div>
            </template>
        </template>

        <!-- Listado clubes -->
        <template v-else-if="route.view === 'clubes' && hub">
            <section class="landing-afiliados__intro">
                <h1 style="font-size:1.25rem;margin:0 0 0.35rem;color:#0e86a9">Clubes — {{ hub.afiliado.nombre }}</h1>
                <p style="margin:0;color:#475569">Seleccione un club para ver sus afiliados o acceder al formulario de afiliación.</p>
                <div class="landing-afiliados__btn-row">
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goHub(hub.afiliado.id)">
                        <i class="fas fa-arrow-left"></i> Volver al afiliado
                    </button>
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goLanding">
                        <i class="fas fa-home"></i> Inicio
                    </button>
                </div>
            </section>

            <div v-if="!clubes.length" class="landing-afiliados__card">
                <p>No hay clubes registrados en esta asociación.</p>
            </div>
            <div v-else class="landing-afiliados__grid">
                <article
                    v-for="c in clubes"
                    :key="c.id"
                    class="landing-afiliados__card landing-afiliados__card--club"
                >
                    <div class="landing-afiliados__card-title">{{ c.nombre }}</div>
                    <p v-if="c.delegado" class="landing-afiliados__card-desc">
                        <i class="fas fa-user-tie me-1"></i>{{ c.delegado }}
                    </p>
                    <div class="landing-afiliados__stats mb-2">
                        <span class="landing-afiliados__badge">{{ c.total_afiliados }} afiliados</span>
                    </div>
                    <div class="landing-afiliados__btn-row">
                        <button type="button" class="landing-afiliados__btn" @click="goClub(hub.afiliado.id, c.id)">
                            <i class="fas fa-users"></i> Ver afiliados
                        </button>
                        <a :href="c.urls.afiliacion" class="landing-afiliados__btn landing-afiliados__btn--outline">
                            <i class="fas fa-user-plus"></i> Afiliarse
                        </a>
                    </div>
                </article>
            </div>
        </template>

        <!-- Detalle club -->
        <template v-else-if="route.view === 'club' && clubDetalle">
            <section class="landing-afiliados__intro">
                <h1 style="font-size:1.25rem;margin:0 0 0.35rem;color:#0e86a9">{{ clubDetalle.club.nombre }}</h1>
                <p style="margin:0;color:#475569">{{ hub?.afiliado?.nombre }}</p>
                <div class="landing-afiliados__btn-row">
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goClubes(route.orgId)">
                        <i class="fas fa-arrow-left"></i> Lista de clubes
                    </button>
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goHub(route.orgId)">
                        <i class="fas fa-building"></i> Afiliado
                    </button>
                </div>
            </section>

            <article class="landing-afiliados__card landing-afiliados__card--full mb-3">
                <div class="landing-afiliados__card-title"><i class="fas fa-home me-2"></i>Información del club</div>
                <dl class="landing-afiliados__detail-grid">
                    <div><dt>Delegado</dt><dd>{{ clubDetalle.club.delegado || '—' }}</dd></div>
                    <div><dt>Total afiliados</dt><dd>{{ clubDetalle.club.total_afiliados }}</dd></div>
                </dl>
                <div class="landing-afiliados__btn-row">
                    <a :href="clubDetalle.urls.afiliacion" class="landing-afiliados__btn">
                        <i class="fas fa-user-plus"></i> Formulario de afiliación
                    </a>
                </div>
            </article>

            <h2 class="landing-afiliados__section-title">Afiliados del club</h2>
            <p v-if="actionMsg" class="landing-afiliados__action-msg">{{ actionMsg }}</p>
            <div v-if="!clubDetalle.afiliados.length" class="landing-afiliados__card">
                <p>Este club aún no tiene afiliados registrados.</p>
                <a :href="clubDetalle.urls.afiliacion" class="landing-afiliados__btn mt-2">
                    <i class="fas fa-user-plus"></i> Solicitar afiliación
                </a>
            </div>
            <div v-else class="landing-afiliados__card landing-afiliados__card--full landing-afiliados__table-wrap">
                <table class="landing-afiliados__table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Estatus</th>
                            <th class="landing-afiliados__th-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in clubDetalle.afiliados" :key="a.id">
                            <td><strong>{{ a.nombre }}</strong></td>
                            <td>
                                <span
                                    class="landing-afiliados__badge"
                                    :class="a.estatus === 'activo' ? 'landing-afiliados__badge--ok' : 'landing-afiliados__badge--muted'"
                                >{{ a.estatus_label }}</span>
                            </td>
                            <td class="landing-afiliados__td-actions">
                                <button type="button" class="landing-afiliados__btn landing-afiliados__btn--sm" @click="goAfiliado(route.orgId, route.clubId, a.id)" title="Ver">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <template v-if="clubDetalle.puede_gestionar">
                                    <a v-if="a.urls.editar" :href="a.urls.editar" class="landing-afiliados__btn landing-afiliados__btn--sm landing-afiliados__btn--outline" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button
                                        v-if="a.estatus === 'activo'"
                                        type="button"
                                        class="landing-afiliados__btn landing-afiliados__btn--sm landing-afiliados__btn--warn"
                                        title="Desactivar"
                                        @click="toggleAfiliado(a)"
                                    >
                                        <i class="fas fa-ban"></i>
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="landing-afiliados__btn landing-afiliados__btn--sm landing-afiliados__btn--ok"
                                        title="Activar"
                                        @click="toggleAfiliado(a)"
                                    >
                                        <i class="fas fa-check"></i>
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Detalle afiliado -->
        <template v-else-if="route.view === 'afiliado' && afiliadoDetalle">
            <section class="landing-afiliados__intro">
                <h1 style="font-size:1.25rem;margin:0 0 0.35rem;color:#0e86a9">{{ afiliadoDetalle.afiliado.nombre }}</h1>
                <p style="margin:0;color:#475569">{{ clubDetalle?.club?.nombre || hub?.afiliado?.nombre }}</p>
                <div class="landing-afiliados__btn-row">
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goClub(route.orgId, route.clubId)">
                        <i class="fas fa-arrow-left"></i> Afiliados del club
                    </button>
                </div>
            </section>

            <article class="landing-afiliados__card landing-afiliados__card--full">
                <dl class="landing-afiliados__detail-grid">
                    <div><dt>Club</dt><dd>{{ afiliadoDetalle.afiliado.club_nombre || '—' }}</dd></div>
                    <div><dt>Cédula</dt><dd>{{ afiliadoDetalle.afiliado.cedula || '—' }}</dd></div>
                    <div><dt>Email</dt><dd>{{ afiliadoDetalle.afiliado.email || '—' }}</dd></div>
                    <div><dt>Estatus</dt><dd>{{ afiliadoDetalle.afiliado.estatus_label }}</dd></div>
                    <div><dt>Última actividad</dt><dd>{{ afiliadoDetalle.afiliado.ultima_actividad_fmt || '—' }}</dd></div>
                </dl>
                <div class="landing-afiliados__btn-row">
                    <a v-if="afiliadoDetalle.urls.ficha_pdf" :href="afiliadoDetalle.urls.ficha_pdf" class="landing-afiliados__btn landing-afiliados__btn--outline" target="_blank" rel="noopener">
                        <i class="fas fa-file-pdf"></i> Ficha PDF
                    </a>
                    <a v-if="afiliadoDetalle.puede_gestionar && afiliadoDetalle.urls.editar" :href="afiliadoDetalle.urls.editar" class="landing-afiliados__btn">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                </div>
            </article>
        </template>

        <!-- Listado torneos -->
        <template v-else-if="route.view === 'torneos' && hub">
            <section class="landing-afiliados__intro">
                <h1 style="font-size:1.25rem;margin:0 0 0.35rem;color:#0e86a9">Torneos — {{ hub.afiliado.nombre }}</h1>
                <p style="margin:0;color:#475569">Realizados y en proceso. Seleccione uno para ver el detalle.</p>
                <div class="landing-afiliados__btn-row">
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goHub(hub.afiliado.id)">
                        <i class="fas fa-arrow-left"></i> Volver al afiliado
                    </button>
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goLanding">
                        <i class="fas fa-home"></i> Inicio
                    </button>
                </div>
            </section>

            <h2 class="landing-afiliados__section-title" v-if="torneos.en_proceso.length">En proceso</h2>
            <div class="landing-afiliados__torneo-list" v-if="torneos.en_proceso.length">
                <button
                    v-for="t in torneos.en_proceso"
                    :key="'p-' + t.id"
                    type="button"
                    class="landing-afiliados__torneo-item landing-afiliados__torneo-item--compact"
                    @click="goTorneo(hub.afiliado.id, t.id)"
                >
                    <div class="landing-afiliados__torneo-compact">
                        <img v-if="t.afiche_url || t.logo_url" :src="t.afiche_url || t.logo_url" alt="" class="landing-afiliados__logo-xs landing-afiliados__torneo-compact__logo">
                        <i v-else class="fas fa-trophy landing-afiliados__torneo-compact__logo landing-afiliados__torneo-compact__logo--icon" aria-hidden="true"></i>
                        <span class="landing-afiliados__torneo-compact__title" :title="t.nombre">{{ t.nombre }}</span>
                        <span class="landing-afiliados__torneo-compact__meta">
                            <span>{{ formatFecha(t.fechator) }}</span>
                            <span>{{ t.lugar || '—' }}</span>
                            <span>{{ t.total_inscritos }} insc.</span>
                        </span>
                        <i class="fas fa-chevron-right landing-afiliados__torneo-compact__chevron" aria-hidden="true"></i>
                    </div>
                </button>
            </div>

            <h2 class="landing-afiliados__section-title" v-if="torneos.realizados.length">Realizados</h2>
            <div class="landing-afiliados__torneo-list" v-if="torneos.realizados.length">
                <button
                    v-for="t in torneos.realizados"
                    :key="'r-' + t.id"
                    type="button"
                    class="landing-afiliados__torneo-item landing-afiliados__torneo-item--compact"
                    @click="goTorneo(hub.afiliado.id, t.id)"
                >
                    <div class="landing-afiliados__torneo-compact">
                        <img v-if="t.afiche_url || t.logo_url" :src="t.afiche_url || t.logo_url" alt="" class="landing-afiliados__logo-xs landing-afiliados__torneo-compact__logo">
                        <i v-else class="fas fa-trophy landing-afiliados__torneo-compact__logo landing-afiliados__torneo-compact__logo--icon" aria-hidden="true"></i>
                        <span class="landing-afiliados__torneo-compact__title" :title="t.nombre">{{ t.nombre }}</span>
                        <span class="landing-afiliados__torneo-compact__meta">
                            <span>{{ formatFecha(t.fechator) }}</span>
                            <span>{{ t.lugar || '—' }}</span>
                            <span>{{ t.total_inscritos }} insc.</span>
                        </span>
                        <i class="fas fa-chevron-right landing-afiliados__torneo-compact__chevron" aria-hidden="true"></i>
                    </div>
                </button>
            </div>
            <div v-if="!torneos.en_proceso.length && !torneos.realizados.length" class="landing-afiliados__card">
                No hay torneos realizados ni en proceso para esta asociación.
            </div>
        </template>

        <!-- Detalle torneo -->
        <template v-else-if="route.view === 'torneo' && torneo">
            <section class="landing-afiliados__intro">
                <h1 style="font-size:1.25rem;margin:0 0 0.35rem;color:#0e86a9">{{ torneo.nombre }}</h1>
                <p style="margin:0;color:#475569">{{ hub?.afiliado?.nombre }}</p>
                <div class="landing-afiliados__btn-row">
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goTorneos(route.orgId)">
                        <i class="fas fa-arrow-left"></i> Lista de torneos
                    </button>
                    <button type="button" class="landing-afiliados__btn landing-afiliados__btn--outline" @click="goLanding">
                        <i class="fas fa-home"></i> Inicio
                    </button>
                </div>
            </section>
            <div class="landing-afiliados__card">
                <img v-if="torneo.afiche_url" :src="torneo.afiche_url" alt="" style="max-width:100%;border-radius:8px;margin-bottom:1rem">
                <dl class="landing-afiliados__detail-grid">
                    <div><dt>Fecha</dt><dd>{{ formatFecha(torneo.fechator) }}</dd></div>
                    <div><dt>Lugar</dt><dd>{{ torneo.lugar || '—' }}</dd></div>
                    <div><dt>Inscritos</dt><dd>{{ torneo.total_inscritos }}</dd></div>
                    <div><dt>Estado</dt><dd>{{ torneo.estado_torneo }}</dd></div>
                    <div v-if="torneo.costo"><dt>Costo</dt><dd>{{ torneo.costo }}</dd></div>
                    <div v-if="torneo.organizacion_responsable"><dt>Responsable</dt><dd>{{ torneo.organizacion_responsable }}</dd></div>
                    <div v-if="torneo.organizacion_telefono"><dt>Contacto</dt><dd>{{ torneo.organizacion_telefono }}</dd></div>
                </dl>
                <p v-if="torneo.descripcion" style="margin-top:1rem;white-space:pre-wrap">{{ torneo.descripcion }}</p>
                <div class="landing-afiliados__btn-row">
                    <a v-if="torneoUrls.resultados" :href="torneoUrls.resultados" class="landing-afiliados__btn">
                        <i class="fas fa-chart-bar"></i> Resultados
                    </a>
                    <a v-if="torneoUrls.detalle_legacy" :href="torneoUrls.detalle_legacy" class="landing-afiliados__btn landing-afiliados__btn--outline">
                        <i class="fas fa-info-circle"></i> Ficha completa
                    </a>
                </div>
            </div>
        </template>
    </main>
</div>

<script>
window.LANDING_AFILIADOS_CONFIG = <?= json_encode([
    'apiUrl' => $api_url,
    'baseUrl' => $base_url,
    'landingUrl' => $landing_url,
    'brand' => $brand,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<script src="<?= htmlspecialchars($base_url) ?>assets/js/landing-afiliados.js"></script>
</body>
</html>
