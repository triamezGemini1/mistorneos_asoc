<?php
/**
 * Landing Page SPA - MisTorneos (segmento ASOC por defecto)
 * Single Page Application con Vue 3 para mejor UX
 * URL oficial: .../public/landing-spa.php
 * Base y logo vía AppHelpers para que funcionen en /pruebas/public, /mistorneos_beta/public, etc.
 */
try {
    require_once __DIR__ . '/../config/bootstrap.php';
    require_once __DIR__ . '/../lib/app_helpers.php';
    require_once __DIR__ . '/../lib/Branding.php';
    $base_url = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'), '/') . '/';
    $show_afiliados_section = class_exists('SegmentConfig', false) && SegmentConfig::feature('landing_afiliados_hub');
    $api_url = $base_url . 'api/landing_data.php';
    $logo_url = Branding::logoUrl();
    $brand_name = Branding::siteName();
    $brand_tagline = Branding::tagline();
    $brand_email = Branding::contactEmail();
    $brand_meta_title = Branding::landingMetaTitle();
    $brand_meta_description = Branding::metaDescription();
    $brand_meta_keywords = Branding::metaKeywords();
    $brand_og_title = Branding::ogTitle();
    $brand_og_description = Branding::ogDescription();
    $brand_theme_color = Branding::themeColor();
    $brand_copyright = Branding::copyrightNotice();
    $entidad_param = isset($_GET['entidad']) ? (int)$_GET['entidad'] : 0;
    if ($entidad_param > 0) {
        $api_url .= '?entidad=' . $entidad_param;
    }
} catch (Throwable $e) {
    error_log('landing-spa.php: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body><p>Error al cargar la página. <a href="login.php">Ir al login</a></p></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="<?= htmlspecialchars($brand_theme_color) ?>">
    <title><?= htmlspecialchars($brand_meta_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($brand_meta_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($brand_meta_keywords) ?>">
    <meta name="robots" content="index, follow">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($base_url . 'landing-spa.php') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($brand_og_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($brand_og_description) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($base_url . 'landing-spa.php') ?>">
    <?php include __DIR__ . '/includes/partials/brand_theme.php'; ?>
    
    <link rel="stylesheet" href="<?= htmlspecialchars($base_url . 'assets/dist/output.css') ?>" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="<?= htmlspecialchars($base_url . 'assets/dist/output.css') ?>"></noscript>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"></noscript>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet"></noscript>
    
    <style>
        /* ========== Tema E-sports: variables y base ========== */
        :root {
            --esports-bg: #0f172a;
            --esports-bg-subtle: linear-gradient(180deg, #0f172a 0%, #0f172a 50%, #0c1222 100%);
            --esports-card-bg: #1e293b;
            --esports-card-border: rgba(255, 255, 255, 0.08);
            --esports-accent: #00f2ff;
            --esports-accent-hover: #33f5ff;
            --esports-accent-glow: rgba(0, 242, 255, 0.4);
            --esports-text: #e2e8f0;
            --esports-text-muted: #94a3b8;
        }
        body { font-family: 'Inter', system-ui, sans-serif; }
        body.esports-theme { background: var(--esports-bg); color: var(--esports-text); min-height: 100vh; }
        body.esports-theme .esports-font-title { font-family: 'Montserrat', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; }
        .esports-theme section:not(#hero) .container { background: var(--esports-card-bg); border-radius: 12px; border: 1px solid var(--esports-card-border); }
        .esports-theme .btn-accent { background: var(--esports-accent); color: #0f172a; font-family: 'Montserrat', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; border: none; transition: box-shadow 0.25s ease, transform 0.2s ease, background 0.2s ease; }
        .esports-theme .btn-accent:hover { background: var(--esports-accent-hover); box-shadow: 0 0 24px var(--esports-accent-glow); transform: translateY(-2px); }
        .esports-theme .btn-accent.hero-cta-primary { font-size: 1.125rem; padding: 1rem 2.5rem; border-radius: 12px; box-shadow: 0 0 20px var(--esports-accent-glow); }
        .esports-theme .btn-accent.hero-cta-primary:hover { box-shadow: 0 0 32px var(--esports-accent-glow), 0 0 48px rgba(0, 242, 255, 0.2); }
        .esports-theme section:not(#hero) { margin-top: 1.5rem; margin-bottom: 1.5rem; }
        .esports-theme section:not(#hero):first-of-type { margin-top: 2rem; }
        .esports-theme section:not(#hero) .container { padding-top: 2rem; padding-bottom: 2rem; }
        .esports-theme #app > div.min-h-screen > p { color: var(--esports-text-muted); }
        /* Shell estático: sin parpadeo antes de Vue (misma jerarquía que nav+hero) */
        #static-landing-shell { flex-shrink: 0; }
        .landing-loading-below-fold { min-height: min(50vh, 28rem); flex: 1 1 auto; }
        .esports-theme section:not(#hero) .container .text-center h2,
        .esports-theme section:not(#hero) .container h2 { color: #f1f5f9 !important; font-family: 'Montserrat', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; }
        .esports-theme section:not(#hero) .container h3,
        .esports-theme section:not(#hero) .container h4,
        .esports-theme section:not(#hero) .container h5 { color: #e2e8f0 !important; }
        .esports-theme section:not(#hero) .container p { color: var(--esports-text-muted) !important; }
        .esports-theme section:not(#hero) .container .text-primary-700,
        .esports-theme section:not(#hero) .container .text-gray-900 { color: #e2e8f0 !important; }
        .esports-theme section:not(#hero) .container .text-gray-600 { color: var(--esports-text-muted) !important; }
        .esports-theme section:not(#hero) .container a:not(.btn-accent):not([class*="bg-"]) { color: var(--esports-accent); }
        .esports-theme section:not(#hero) .container a:not(.btn-accent):not([class*="bg-"]):hover { color: var(--esports-accent-hover); }
        .landing-logo-org { max-height: 60%; max-width: 60%; width: auto; height: auto; object-fit: contain; }
        .fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
        .fade-enter-from, .fade-leave-to { opacity: 0; }
        .slide-enter-active, .slide-leave-active { transition: transform 0.3s ease; }
        .slide-enter-from { transform: translateY(-10px); opacity: 0; }
        .slide-leave-to { transform: translateY(10px); opacity: 0; }
        /* Tarjetas y secciones con fondo claro: texto negro (especificidad alta para ganar al tema .esports-theme) */
        .esports-theme #documentos .container,
        .esports-theme section[class*="from-slate-50"] .container,
        .esports-theme section[class*="to-blue-50"] .container {
            background: transparent !important;
            border: none !important;
            color: #111827 !important;
        }
        .esports-theme #documentos .container h2,
        .esports-theme #documentos .container h3,
        .esports-theme #documentos .container h4,
        .esports-theme #documentos .container p,
        .esports-theme section[class*="from-slate-50"] .container h2,
        .esports-theme section[class*="from-slate-50"] .container h3,
        .esports-theme section[class*="from-slate-50"] .container p { color: #111827 !important; }
        .esports-theme #documentos .container .landing-doc-card__title,
        .esports-theme #documentos .landing-doc-card .landing-doc-card__title,
        #documentos .landing-doc-card__title {
            color: #000000 !important;
            font-weight: 800 !important;
        }

        /* Tarjetas de documentos e invitaciones FVD: fondo blanco y letras negras */
        .esports-theme #documentos .bg-white.rounded-2xl,
        .esports-theme #documentos .bg-white.rounded-xl,
        .esports-theme #documentos .bg-white\/60 {
            background: #ffffff !important;
            color: #111827 !important;
        }
        .esports-theme #documentos .bg-white.rounded-2xl h3,
        .esports-theme #documentos .bg-white.rounded-2xl h4,
        .esports-theme #documentos .bg-white.rounded-2xl p,
        .esports-theme #documentos .bg-white.rounded-xl h3,
        .esports-theme #documentos .bg-white.rounded-xl h4,
        .esports-theme #documentos .bg-white.rounded-xl p,
        .esports-theme #documentos .bg-white\/60 p,
        .esports-theme #documentos .bg-white\/60 code { color: #111827 !important; }
        .esports-theme #documentos .text-gray-600 { color: #374151 !important; }
        .esports-theme #documentos .text-primary-700 { color: #1e40af !important; }
        /* Documentos descargables — fila compacta (hasta 5 columnas) */
        #documentos .landing-docs-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
            max-width: 80rem;
            margin: 0 auto;
        }
        @media (min-width: 640px) {
            #documentos .landing-docs-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (min-width: 1024px) {
            #documentos .landing-docs-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        }
        #documentos .landing-doc-card {
            background: #00C3FD !important;
            border: 1px solid rgba(255, 255, 255, 0.55);
            border-radius: 0.875rem;
            padding: 1.25rem 0.875rem;
            text-align: center;
            box-shadow: 0 8px 20px rgba(0, 195, 253, 0.35);
            transition: box-shadow 0.2s ease, transform 0.2s ease;
            min-height: 10.5rem;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
        }
        #documentos .landing-doc-card:hover {
            box-shadow: 0 12px 28px rgba(0, 195, 253, 0.45);
            transform: translateY(-3px);
        }
        #documentos .landing-doc-card__icon {
            width: 3.75rem;
            height: 3.75rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
            font-size: 1.75rem;
            background: rgba(255, 255, 255, 0.92);
        }
        #documentos .landing-doc-card__icon--pdf,
        #documentos .landing-doc-card__icon--img { color: #ea580c; }
        #documentos .landing-doc-card__title {
            font-size: 0.9375rem;
            line-height: 1.3;
            margin: 0 0 0.75rem;
            min-height: 3.25rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        #documentos .landing-doc-card__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
            margin-top: auto;
            padding-top: 0.35rem;
        }
        #documentos .landing-doc-card__btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.125rem;
            height: 2.125rem;
            font-size: 0.8125rem;
            font-weight: 600;
            border-radius: 0.5rem;
            text-decoration: none;
        }
        #documentos .landing-doc-card__btn--view { background: #ea580c; color: #fff; }
        #documentos .landing-doc-card__btn--view:hover { background: #c2410c; color: #fff; }
        #documentos .landing-doc-card__btn--dl { background: #fff; color: #ea580c; border: 1px solid rgba(234, 88, 12, 0.35); }
        #documentos .landing-doc-card__btn--dl:hover { background: #fff7ed; color: #c2410c; }

        /* Contenedor tipo tarjeta blanca (faq, comentarios): forzar sobre tema */
        .esports-theme .landing-card-light,
        .esports-theme section#faq .container.landing-card-light,
        .esports-theme section#comentarios .container.landing-card-light {
            background: #ffffff !important;
            color: #111827 !important;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            padding: 2rem !important;
        }
        .esports-theme .landing-card-light h2,
        .esports-theme .landing-card-light h3,
        .esports-theme .landing-card-light h4,
        .esports-theme .landing-card-light h5,
        .esports-theme .landing-card-light h6,
        .esports-theme .landing-card-light p,
        .esports-theme .landing-card-light li,
        .esports-theme .landing-card-light span:not([class*="bg-"]):not([class*="text-white"]),
        .esports-theme .landing-card-light label,
        .esports-theme .landing-card-light summary { color: #111827 !important; }
        /* Títulos en negro: mayor especificidad que .esports-theme section:not(#hero) .container h2/h3 */
        .esports-theme section#faq .container.landing-card-light .text-center h2,
        .esports-theme section#faq .container.landing-card-light h2,
        .esports-theme section#faq .container.landing-card-light h3,
        .esports-theme section#faq .container.landing-card-light h4,
        .esports-theme section#faq .container.landing-card-light details summary,
        .esports-theme section#comentarios .container.landing-card-light .text-center h2,
        .esports-theme section#comentarios .container.landing-card-light h2,
        .esports-theme section#comentarios .container.landing-card-light h3,
        .esports-theme section#comentarios .container.landing-card-light h4 { color: #111827 !important; }
        .esports-theme .landing-card-light .text-gray-600,
        .esports-theme .landing-card-light .text-gray-500 { color: #374151 !important; }
        .esports-theme .landing-card-light .text-primary-700 { color: #1e40af !important; }
        .esports-theme .landing-card-light a:not([class*="bg-"]):not(.btn-accent) { color: #1d4ed8 !important; }
        .esports-theme .landing-card-light a:not([class*="bg-"]):not(.btn-accent):hover { color: #1e40af !important; }

        /* ========== Mobile-First: formularios ========== */
        .landing-form-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; width: 100%; }
        @media (min-width: 768px) { .landing-form-grid { grid-template-columns: repeat(2, 1fr); gap: 1.25rem; } }
        @media (min-width: 1024px) { .landing-form-grid { grid-template-columns: repeat(3, 1fr); gap: 1.5rem; } }
        .landing-form-grid-1-3 { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        @media (min-width: 1024px) { .landing-form-grid-1-3 { grid-template-columns: 1fr 2fr; } }
        .landing-form-grid-full { grid-column: 1 / -1; }
        .landing-input-touch { min-height: 44px; padding: 12px 16px; font-size: 16px; width: 100%; box-sizing: border-box; border-radius: 0.5rem; }
        .landing-btn-touch { min-height: 44px; padding: 12px 20px; font-size: 16px; }
        .landing-label-block { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
        @media (max-width: 359px) { .landing-label-block { width: 100%; min-width: 0; } .landing-label-block label { order: -1; } }
        .landing-field { width: 100%; min-width: 0; }
        @media (max-width: 359px) { .landing-field { max-width: 100%; } }
        .landing-card-mobile { width: 100%; max-width: 100%; }
        @media (min-width: 1024px) { .landing-card-mobile { max-width: 42rem; } }
        /* Tablas → Cards en móvil (no se usa tabla en esta página; útil si se incluye después) */
        @media (max-width: 767px) {
            .landing-table-as-cards { display: block; }
            .landing-table-as-cards thead { display: none; }
            .landing-table-as-cards tr { display: block; margin-bottom: 1rem; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1rem; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
            .landing-table-as-cards td { display: block; padding: 0.5rem 0; border: none; }
            .landing-table-as-cards td::before { content: attr(data-label); font-weight: 600; color: #374151; display: block; margin-bottom: 0.25rem; }
        }

        /* ========== Formulario "Envía tu comentario" – Mobile First + estética ========== */
        .comment-form-container { max-width: 800px; margin-left: auto; margin-right: auto; }
        .comment-form-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; width: 100%; padding: 0; }
        @media (min-width: 768px) {
            .comment-form-grid { grid-template-columns: repeat(2, 1fr); gap: 1.25rem; }
        }
        .comment-form-full { grid-column: 1 / -1; }
        .comment-form-field { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0; }
        .comment-form-field label { font-size: 0.875rem; font-weight: 600; color: #374151; }
        .comment-form-input-wrap { position: relative; width: 100%; }
        .comment-form-input-wrap .comment-form-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 1rem; pointer-events: none; z-index: 1; }
        .comment-form-input-wrap.comment-form-icon-textarea .comment-form-icon { top: 18px; transform: none; }
        .comment-form-input {
            width: 100%; min-height: 44px; padding: 12px 16px; padding-left: 2.75rem;
            font-size: 16px; box-sizing: border-box;
            border: 1px solid #e5e7eb; border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, outline 0.2s ease;
        }
        .comment-form-input:focus { outline: none; border-color: #1a365d; box-shadow: 0 0 0 3px rgba(26,54,93,0.15); }
        .comment-form-input-wrap textarea.comment-form-input { padding-top: 12px; min-height: 120px; resize: vertical; }
        .comment-form-input-wrap textarea.comment-form-input { padding-left: 2.75rem; }
        .comment-form-btn {
            min-height: 44px; padding: 12px 24px; font-size: 16px; font-weight: 700;
            border: none; border-radius: 8px; cursor: pointer;
            background: #1a365d; color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
        }
        .comment-form-btn:hover:not(:disabled) { background: #152b4a; box-shadow: 0 4px 8px rgba(0,0,0,0.12); }
        .comment-form-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        @media (max-width: 767px) { .comment-form-btn { width: 100%; } }
        @media (min-width: 768px) { .comment-form-btn { width: auto; margin-left: auto; display: block; } }
        .comment-form-stars { display: flex; align-items: center; flex-wrap: wrap; gap: 0.25rem; min-height: 44px; }
        .comment-form-stars label { cursor: pointer; padding: 8px; margin: -8px; }
        /* Asociaciones afiliadas (sección ASOC) */
        #asociaciones-afiliadas .afiliado-card {
            background: #00C3FD;
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.55);
            padding: 1.5rem 1.25rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none;
            color: #4a1942;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            height: 100%;
            box-shadow: 0 8px 24px rgba(252, 97, 158, 0.18);
        }
        #asociaciones-afiliadas .afiliado-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 32px rgba(252, 97, 158, 0.28);
        }
        #asociaciones-afiliadas .afiliado-card__logo-wrap {
            width: 96px;
            height: 96px;
            margin: 0 auto 1rem;
            background: rgba(255, 255, 255, 0.92);
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
        }
        #asociaciones-afiliadas .afiliado-card__logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
            border-radius: 0.5rem;
        }
        #asociaciones-afiliadas .afiliado-card__title {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 0.35rem;
            color: #4a1942;
        }
        #asociaciones-afiliadas .afiliado-card__entidad {
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            color: #7c3a6b;
        }
        #asociaciones-afiliadas .afiliado-card__stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 0.75rem;
            justify-content: center;
            font-size: 0.75rem;
            color: #6b3d5c;
        }
        #asociaciones-afiliadas .afiliado-card__cta {
            display: inline-block;
            margin-top: 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #9d174d;
        }
    </style>
</head>
<body class="esports-theme antialiased" data-landing-build="estacion-20260625">
    <div id="app" class="min-h-screen flex flex-col">
        <div v-if="loading" class="min-h-screen flex flex-col bg-[#0f172a]">
            <div id="static-landing-shell" class="static-landing-shell">
                <nav class="esports-nav bg-[#0f172a]/95 border-b border-white/10 shadow-lg sticky top-0 z-50 backdrop-blur-md" aria-label="Principal">
                    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center justify-between h-16 md:h-20">
                            <a href="<?= htmlspecialchars($base_url . 'landing-spa.php') ?>" class="flex items-center gap-2 text-white font-bold hover:opacity-90 transition-opacity" title="<?= htmlspecialchars($brand_name) ?>">
                                <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($brand_name) ?>" width="200" height="50" fetchpriority="high" loading="eager" decoding="async" class="h-8 md:h-10 w-auto max-h-10 object-contain object-left">
                                <span class="hidden sm:inline text-sm md:text-base font-semibold tracking-tight"><?= htmlspecialchars($brand_name) ?></span>
                            </a>
                            <div class="hidden md:flex items-center space-x-1">
                                <a href="#documentos" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Documentos</a>
                                <?php if ($show_afiliados_section): ?>
                                <a href="#asociaciones-afiliadas" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Asociaciones</a>
                                <?php endif; ?>
                                <a href="#faq" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">FAQ</a>
                                <a href="#comentarios" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Comentarios</a>
                                <a href="<?= htmlspecialchars($base_url . 'login.php') ?>" class="ml-4 px-6 py-2.5 btn-accent rounded-lg font-semibold transition-all shadow-lg"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                            </div>
                            <span class="md:hidden text-white p-2 rounded-lg opacity-90" aria-hidden="true"><i class="fas fa-bars text-xl"></i></span>
                        </div>
                    </div>
                </nav>
                <section id="hero" class="relative overflow-hidden" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);">
                    <div class="absolute inset-0 opacity-30" style="background-image: radial-gradient(circle at 2px 2px, rgba(0,242,255,0.15) 1px, transparent 0); background-size: 32px 32px;"></div>
                    <div class="absolute inset-0 bg-gradient-to-r from-[#00f2ff]/10 via-transparent to-[#00f2ff]/10"></div>
                    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-24 md:py-32 lg:py-40 relative z-10">
                        <div class="max-w-4xl mx-auto text-center">
                            <h1 class="esports-font-title text-4xl md:text-5xl lg:text-7xl mb-6 leading-tight text-white tracking-tight">
                                Domina la Mesa,<br><span class="text-[#00f2ff]">Conviértete en Leyenda</span>
                            </h1>
                            <p class="text-lg md:text-xl text-slate-400 mb-10 max-w-2xl mx-auto leading-relaxed">La plataforma de torneos de dominó. Consulta torneos, resultados y ranking en tu asociación afiliada.</p>
                            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                                <?php if ($show_afiliados_section): ?>
                                <a href="#asociaciones-afiliadas" class="hero-cta-primary btn-accent inline-flex items-center justify-center w-full sm:w-auto px-10 py-5 text-lg"><i class="fas fa-building mr-3"></i>Asociaciones afiliadas</a>
                                <?php else: ?>
                                <a href="#documentos" class="hero-cta-primary btn-accent inline-flex items-center justify-center w-full sm:w-auto px-10 py-5 text-lg"><i class="fas fa-file-alt mr-3"></i>Documentos</a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars($base_url . 'login.php') ?>" class="w-full sm:w-auto px-8 py-4 text-slate-300 font-semibold rounded-xl border border-slate-500 hover:bg-white/5 hover:text-white transition-all text-center"><i class="fas fa-sign-in-alt mr-2"></i>Ya tengo cuenta</a>
                            </div>
                        </div>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0"><svg class="w-full h-12 md:h-20 block" viewBox="0 0 1200 120" preserveAspectRatio="none" aria-hidden="true"><path d="M0,0 C150,80 350,80 600,40 C850,0 1050,0 1200,40 L1200,120 L0,120 Z" fill="#0f172a"></path></svg></div>
                </section>
            </div>
            <div class="landing-loading-below-fold flex flex-col items-center justify-center py-12 px-4 border-t border-white/5">
                <div class="flex flex-col items-center gap-4">
                    <i class="fas fa-spinner fa-spin text-5xl text-[#00f2ff]" aria-hidden="true"></i>
                    <p class="text-slate-400 font-medium">Cargando contenido…</p>
                </div>
            </div>
        </div>
        <div v-else-if="error" class="min-h-screen flex flex-col items-center justify-center py-20 px-4 flex-1">
            <div class="bg-red-50 border border-red-200 rounded-xl p-8 max-w-md text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mb-4"></i>
                <p class="text-red-700 font-medium mb-4">{{ error }}</p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <button @click="cargarDatos" class="px-6 py-2 bg-primary-500 text-white rounded-lg font-semibold hover:bg-primary-600">
                        Reintentar
                    </button>
                    <a :href="baseUrl + 'landing.php'" class="px-6 py-2 bg-gray-600 text-white rounded-lg font-semibold hover:bg-gray-700 text-center no-underline">
                        Usar versión clásica
                    </a>
                </div>
            </div>
        </div>
        <landing-content v-else :data="data" :base-url="baseUrl" :logo-url="logoUrl" @refresh-comentarios="cargarDatos"></landing-content>
    </div>

    <script type="text/x-template" id="landing-template">
        <div class="min-h-screen flex flex-col">
            <!-- Navbar -->
            <nav class="esports-nav bg-[#0f172a]/95 border-b border-white/10 shadow-lg sticky top-0 z-50 backdrop-blur-md">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between h-16 md:h-20">
                        <a :href="baseUrl + 'landing-spa.php'" @click.prevent="scrollToSection('hero')" class="flex items-center gap-2 text-white font-bold hover:opacity-90 transition-opacity" title="<?= htmlspecialchars($brand_name) ?>">
                            <img :src="logoUrl" alt="<?= htmlspecialchars($brand_name) ?>" width="200" height="50" fetchpriority="high" loading="eager" decoding="async" class="h-8 md:h-10 w-auto max-h-10 object-contain object-left">
                            <span class="hidden sm:inline text-sm md:text-base font-semibold tracking-tight"><?= htmlspecialchars($brand_name) ?></span>
                        </a>
                        <div class="hidden md:flex items-center space-x-1">
                            <a href="#documentos" @click.prevent="scrollToSection('documentos')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Documentos</a>
                            <a v-if="showAfiliadosHub" href="#asociaciones-afiliadas" @click.prevent="scrollToSection('asociaciones-afiliadas')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Asociaciones</a>
                            <a href="#faq" @click.prevent="scrollToSection('faq')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">FAQ</a>
                            <a href="#comentarios" @click.prevent="scrollToSection('comentarios')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Comentarios</a>
                            <a :href="baseUrl + 'login.php'" class="ml-4 px-6 py-2.5 btn-accent rounded-lg font-semibold transition-all shadow-lg"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                        </div>
                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden text-white p-2 rounded-lg hover:bg-white/10"><i class="fas fa-bars text-xl"></i></button>
                    </div>
                    <div v-show="mobileMenuOpen" class="md:hidden pb-4">
                        <div class="flex flex-col space-y-2">
                            <a href="#" @click.prevent="scrollToSection('documentos')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Documentos</a>
                            <a v-if="showAfiliadosHub" href="#" @click.prevent="scrollToSection('asociaciones-afiliadas')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Asociaciones</a>
                            <a href="#" @click.prevent="scrollToSection('faq')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">FAQ</a>
                            <a href="#" @click.prevent="scrollToSection('comentarios')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Comentarios</a>
                            <a :href="baseUrl + 'login.php'" class="mt-2 px-4 py-2.5 btn-accent rounded-lg text-center inline-block"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Hero -->
            <section id="hero" class="relative overflow-hidden" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);">
                <div class="absolute inset-0 opacity-30" style="background-image: radial-gradient(circle at 2px 2px, rgba(0,242,255,0.15) 1px, transparent 0); background-size: 32px 32px;"></div>
                <div class="absolute inset-0 bg-gradient-to-r from-[#00f2ff]/10 via-transparent to-[#00f2ff]/10"></div>
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-24 md:py-32 lg:py-40 relative z-10">
                    <div class="max-w-4xl mx-auto text-center">
                        <h1 class="esports-font-title text-4xl md:text-5xl lg:text-7xl mb-6 leading-tight text-white tracking-tight">
                            Domina la Mesa,<br><span class="text-[#00f2ff]">Conviértete en Leyenda</span>
                        </h1>
                        <p class="text-lg md:text-xl text-slate-400 mb-10 max-w-2xl mx-auto leading-relaxed">La plataforma de torneos de dominó. Consulta torneos, resultados y ranking en tu asociación afiliada.</p>
                        <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                            <a v-if="showAfiliadosHub" href="#" @click.prevent="scrollToSection('asociaciones-afiliadas')" class="hero-cta-primary btn-accent inline-flex items-center justify-center w-full sm:w-auto px-10 py-5 text-lg"><i class="fas fa-building mr-3"></i>Asociaciones afiliadas</a>
                            <a v-else href="#" @click.prevent="scrollToSection('documentos')" class="hero-cta-primary btn-accent inline-flex items-center justify-center w-full sm:w-auto px-10 py-5 text-lg"><i class="fas fa-file-alt mr-3"></i>Documentos</a>
                            <a :href="baseUrl + 'login.php'" class="w-full sm:w-auto px-8 py-4 text-slate-300 font-semibold rounded-xl border border-slate-500 hover:bg-white/5 hover:text-white transition-all text-center"><i class="fas fa-sign-in-alt mr-2"></i>Ya tengo cuenta</a>
                        </div>
                    </div>
                </div>
                <div class="absolute bottom-0 left-0 right-0"><svg class="w-full h-12 md:h-20" viewBox="0 0 1200 120" preserveAspectRatio="none"><path d="M0,0 C150,80 350,80 600,40 C850,0 1050,0 1200,40 L1200,120 L0,120 Z" fill="#0f172a"></path></svg></div>
            </section>

            <!-- Asociaciones afiliadas (portal por asociación) -->
            <section v-if="showAfiliadosHub" id="asociaciones-afiliadas" class="py-16 md:py-24" style="background: linear-gradient(180deg, #00d0fa 0%, #b8f0fc 100%);">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center mb-12">
                        <h2 class="text-3xl md:text-4xl font-bold mb-4" style="color:#0e86a9"><i class="fas fa-building mr-3"></i>Asociaciones afiliadas</h2>
                        <p class="text-lg max-w-2xl mx-auto" style="color:#1e3a5f">Seleccione su asociación para consultar torneos realizados y ranking del estado.</p>
                    </div>
                    <div v-if="!data.afiliados?.length" class="text-center py-10 bg-white/70 rounded-2xl max-w-xl mx-auto">
                        <i class="fas fa-building text-4xl mb-3" style="color:#0e86a9"></i>
                        <p style="color:#1e3a5f">No hay asociaciones activas publicadas en este momento.</p>
                    </div>
                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-6xl mx-auto">
                        <a
                            v-for="a in data.afiliados"
                            :key="a.id"
                            :href="afiliadoPortalUrl(a.id)"
                            class="afiliado-card"
                        >
                            <div class="afiliado-card__logo-wrap">
                                <img v-if="a.logo_url" :src="a.logo_url" :alt="a.nombre" class="afiliado-card__logo">
                                <i v-else class="fas fa-building text-3xl" style="color:#FC619E"></i>
                            </div>
                            <h3 class="afiliado-card__title">{{ a.nombre }}</h3>
                            <p v-if="a.entidad_nombre" class="afiliado-card__entidad">{{ a.entidad_nombre }}</p>
                            <div class="afiliado-card__stats">
                                <span><i class="fas fa-users mr-1"></i>{{ a.afiliados ?? 0 }} afiliados</span>
                                <span><i class="fas fa-home mr-1"></i>{{ a.clubes ?? 0 }} clubes</span>
                                <span><i class="fas fa-trophy mr-1"></i>{{ a.torneos ?? 0 }} torneos</span>
                            </div>
                            <span class="afiliado-card__cta">Torneos y ranking del estado <i class="fas fa-arrow-right ml-1"></i></span>
                        </a>
                    </div>
                </div>
            </section>

            <!-- Documentos oficiales de dominó -->
            <section id="documentos" class="py-12 md:py-16 bg-gradient-to-br from-slate-50 to-blue-50">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center mb-8">
                        <h2 class="text-2xl md:text-3xl font-bold text-primary-700 mb-2"><i class="fas fa-file-alt mr-2 text-accent"></i>Documentos oficiales</h2>
                        <p class="text-sm md:text-base text-gray-600 max-w-2xl mx-auto">Consulte o descargue reglamentos, circuitos e invitaciones oficiales.</p>
                    </div>
                    <div v-if="documentosDescarga.length" class="landing-docs-grid">
                        <article v-for="doc in documentosDescarga" :key="doc.path" class="landing-doc-card">
                            <div class="landing-doc-card__icon" :class="docIconClass(doc)">
                                <i :class="docIconName(doc)"></i>
                            </div>
                            <h3 class="landing-doc-card__title">{{ doc.titulo }}</h3>
                            <div class="landing-doc-card__actions">
                                <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path)" target="_blank" rel="noopener noreferrer" class="landing-doc-card__btn landing-doc-card__btn--view" title="Ver en línea"><i class="fas fa-eye"></i></a>
                                <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path) + '&download=1'" class="landing-doc-card__btn landing-doc-card__btn--dl" title="Descargar" download><i class="fas fa-download"></i></a>
                            </div>
                        </article>
                    </div>
                    <div v-else class="text-center py-8 bg-white/60 rounded-xl max-w-xl mx-auto">
                        <i class="fas fa-folder-open text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-600 text-sm">Próximamente se publicarán aquí los documentos oficiales.</p>
                    </div>
                </div>
            </section>

            <!-- FAQ -->
            <section id="faq" class="py-16 md:py-24 bg-gray-100">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 landing-card-light">
                    <div class="text-center mb-12">
                        <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-primary-700 mb-4">Preguntas Frecuentes</h2>
                        <p class="text-lg md:text-xl text-gray-600 max-w-2xl mx-auto">Todo lo que necesitas saber sobre <?= htmlspecialchars($brand_name) ?></p>
                    </div>
                    <div class="max-w-4xl mx-auto space-y-4">
                        <details class="group bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition-all border border-gray-200">
                            <summary class="flex items-center justify-between cursor-pointer font-bold text-lg text-gray-900 list-none">
                                <span><i class="fas fa-question-circle text-primary-500 mr-3"></i>¿Cómo me inscribo en un torneo?</span>
                                <i class="fas fa-chevron-down text-primary-500 group-open:rotate-180 transition-transform"></i>
                            </summary>
                            <p class="mt-4 text-gray-600 pl-10 leading-relaxed">Ingresa al portal de tu <strong>asociación afiliada</strong>, consulta los torneos publicados y sigue las indicaciones de inscripción de cada uno. También puedes contactar al organizador con los datos de la ficha del torneo.</p>
                        </details>
                        <details class="group bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition-all border border-gray-200">
                            <summary class="flex items-center justify-between cursor-pointer font-bold text-lg text-gray-900 list-none">
                                <span><i class="fas fa-question-circle text-primary-500 mr-3"></i>¿Es gratuito participar en los torneos?</span>
                                <i class="fas fa-chevron-down text-primary-500 group-open:rotate-180 transition-transform"></i>
                            </summary>
                            <p class="mt-4 text-gray-600 pl-10 leading-relaxed">Depende del torneo. Toda la información sobre costos está disponible en la ficha de cada torneo.</p>
                        </details>
                        <details class="group bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition-all border border-gray-200">
                            <summary class="flex items-center justify-between cursor-pointer font-bold text-lg text-gray-900 list-none">
                                <span><i class="fas fa-question-circle text-primary-500 mr-3"></i>¿Puedo ver los resultados de torneos anteriores?</span>
                                <i class="fas fa-chevron-down text-primary-500 group-open:rotate-180 transition-transform"></i>
                            </summary>
                            <p class="mt-4 text-gray-600 pl-10 leading-relaxed">¡Por supuesto! Los resultados están disponibles en el portal de cada <a :href="baseUrl + 'landing-afiliados.php'" class="text-primary-600 font-semibold hover:underline">asociación afiliada</a>, en la sección de torneos realizados.</p>
                        </details>
                    </div>
                    <div class="text-center mt-12">
                        <a href="#" @click.prevent="scrollToSection('comentarios')" class="inline-block bg-primary-500 text-white px-8 py-3 rounded-lg font-semibold hover:bg-primary-600 transition-all shadow-lg"><i class="fas fa-comments mr-2"></i>Envíanos tu Consulta</a>
                    </div>
                </div>
            </section>

            <!-- Comentarios -->
            <section id="comentarios" class="py-16 md:py-24 bg-gray-100">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 landing-card-light">
                    <div class="text-center mb-12">
                        <h2 class="text-3xl md:text-4xl font-bold text-primary-700 mb-4"><i class="fas fa-comments mr-3 text-accent"></i>Comentarios y Testimonios</h2>
                        <p class="text-lg text-gray-600">La opinión de nuestra comunidad es muy importante</p>
                    </div>
                    <div v-if="commentSuccess" class="max-w-4xl mx-auto mb-6">
                        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg shadow-md"><i class="fas fa-check-circle mr-3"></i>{{ commentSuccess }}</div>
                    </div>
                    <div v-if="commentErrors.length" class="max-w-4xl mx-auto mb-6">
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg shadow-md">
                            <div v-for="err in commentErrors" :key="err">{{ err }}</div>
                        </div>
                    </div>
                    <div class="max-w-7xl mx-auto">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <div class="bg-white rounded-2xl shadow-lg p-6 border border-gray-200 hover:shadow-xl transition-shadow">
                                    <h3 class="text-2xl font-bold text-gray-900 mb-6"><i class="fas fa-comment-dots text-primary-500 mr-2"></i>Envía tu Comentario</h3>
                                    <template v-if="data.user">
                                        <form @submit.prevent="enviarComentario" class="comment-form-grid">
                                            <div class="bg-primary-50 p-3 rounded-lg comment-form-full" style="margin-bottom: 0;">
                                                <p class="text-sm text-primary-700 mb-0"><i class="fas fa-user-check mr-2"></i>Comentando como: <strong>{{ data.user.nombre }}</strong></p>
                                            </div>
                                            <div class="comment-form-field">
                                                <label>Tipo *</label>
                                                <div class="comment-form-input-wrap">
                                                    <i class="fas fa-tag comment-form-icon" aria-hidden="true"></i>
                                                    <select v-model="commentForm.tipo" required class="comment-form-input">
                                                        <option value="comentario">Comentario</option>
                                                        <option value="sugerencia">Sugerencia</option>
                                                        <option value="testimonio">Testimonio</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="comment-form-field">
                                                <label>Calificación (opcional)</label>
                                                <div class="comment-form-stars">
                                                    <label v-for="i in 5" :key="i">
                                                        <input type="radio" v-model="commentForm.calificacion" :value="i" class="hidden">
                                                        <i class="far fa-star text-2xl hover:text-yellow-500 transition-colors" :class="commentForm.calificacion >= i ? 'fas text-yellow-400' : 'text-yellow-400'"></i>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="comment-form-field comment-form-full">
                                                <label>Mensaje *</label>
                                                <div class="comment-form-input-wrap comment-form-icon-textarea">
                                                    <i class="fas fa-comment comment-form-icon" aria-hidden="true"></i>
                                                    <textarea v-model="commentForm.contenido" rows="5" required placeholder="Escribe tu comentario..." class="comment-form-input"></textarea>
                                                </div>
                                            </div>
                                            <div class="comment-form-full">
                                                <button type="submit" :disabled="commentSending" class="comment-form-btn">
                                                    <i v-if="commentSending" class="fas fa-spinner fa-spin mr-2"></i>
                                                    <i v-else class="fas fa-paper-plane mr-2"></i>{{ commentSending ? 'Enviando...' : 'Enviar Comentario' }}
                                                </button>
                                            </div>
                                            <p class="text-xs text-gray-500 text-center comment-form-full mb-0"><i class="fas fa-shield-alt mr-1"></i>Los comentarios son moderados antes de publicarse</p>
                                        </form>
                                    </template>
                                    <div v-else class="text-center py-8">
                                        <i class="fas fa-lock text-4xl text-gray-400 mb-4"></i>
                                        <p class="text-gray-600 mb-4">Debes iniciar sesión para publicar comentarios</p>
                                        <a :href="baseUrl + 'login.php?redirect=' + encodeURIComponent(baseUrl + 'landing-spa.php#comentarios')" class="inline-block bg-primary-500 text-white px-6 py-3 rounded-lg font-semibold hover:bg-primary-600 transition-all"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                                    </div>
                                </div>
                            <div class="space-y-6">
                                <div v-if="data.comentarios?.length" class="space-y-6">
                                    <div class="bg-white rounded-2xl shadow-lg p-6 mb-6 border border-gray-200">
                                        <div class="grid grid-cols-3 gap-4 text-center">
                                            <div><div class="text-3xl font-bold text-primary-500">{{ data.comentarios.filter(c => c.tipo === 'comentario').length }}</div><div class="text-sm text-gray-600">Comentarios</div></div>
                                            <div><div class="text-3xl font-bold text-purple-600">{{ data.comentarios.filter(c => c.tipo === 'sugerencia').length }}</div><div class="text-sm text-gray-600">Sugerencias</div></div>
                                            <div><div class="text-3xl font-bold text-yellow-600">{{ data.comentarios.filter(c => c.tipo === 'testimonio').length }}</div><div class="text-sm text-gray-600">Testimonios</div></div>
                                        </div>
                                    </div>
                                    <div v-for="c in data.comentarios" :key="c.id" class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-shadow border border-gray-200">
                                        <div class="flex items-start justify-between mb-4">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-12 h-12 bg-gradient-to-br from-primary-500 to-primary-700 rounded-full flex items-center justify-center text-white font-bold">{{ (c.nombre || 'U').charAt(0).toUpperCase() }}</div>
                                                <div>
                                                    <h4 class="font-bold text-gray-900">{{ c.nombre }} <span v-if="c.usuario_username" class="text-xs text-primary-500 ml-2"><i class="fas fa-user-check"></i> Usuario registrado</span></h4>
                                                    <span class="text-xs text-gray-500">{{ new Date(c.fecha_creacion).toLocaleString('es-VE') }}</span>
                                                </div>
                                            </div>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold" :class="c.tipo === 'comentario' ? 'bg-blue-100 text-blue-800' : c.tipo === 'sugerencia' ? 'bg-purple-100 text-purple-800' : 'bg-yellow-100 text-yellow-800'">{{ (c.tipo || 'comentario').charAt(0).toUpperCase() + (c.tipo || 'comentario').slice(1) }}</span>
                                        </div>
                                        <div v-if="c.calificacion" class="mb-3">
                                            <i v-for="i in 5" :key="i" class="fas fa-star" :class="i <= c.calificacion ? 'text-yellow-400' : 'text-gray-300'"></i>
                                        </div>
                                        <p class="text-gray-700 leading-relaxed whitespace-pre-wrap">{{ c.contenido }}</p>
                                    </div>
                                </div>
                                <div v-else class="bg-white rounded-2xl shadow-lg p-12 text-center border border-gray-200">
                                    <i class="fas fa-comment-slash text-gray-400 text-6xl mb-4"></i>
                                    <h3 class="text-2xl font-bold text-gray-900 mb-2">No hay comentarios aún</h3>
                                    <p class="text-gray-600 mb-6">Sé el primero en compartir tu opinión con la comunidad.</p>
                                    <a v-if="!data.user" :href="baseUrl + 'login.php?redirect=' + encodeURIComponent(baseUrl + 'landing-spa.php#comentarios')" class="inline-block bg-gradient-to-r from-primary-500 to-primary-700 text-white px-6 py-3 rounded-lg font-semibold hover:from-primary-600 hover:to-primary-800 transition-all"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión para Comentar</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Footer -->
            <footer class="bg-gray-900 text-white py-12">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex flex-col md:flex-row items-center justify-between">
                        <div class="mb-4 md:mb-0">
                            <h5 class="text-xl font-bold mb-2 flex items-center">
                                <img :src="logoUrl" alt="<?= htmlspecialchars($brand_name) ?>" class="h-6 mr-2">
                                <?= htmlspecialchars($brand_name) ?>
                            </h5>
                            <p class="text-gray-400"><?= htmlspecialchars($brand_tagline) ?></p>
                        </div>
                        <div class="text-center md:text-right">
                            <p class="text-gray-400 mb-1 flex items-center justify-center md:justify-end"><i class="fas fa-envelope mr-2"></i><?= htmlspecialchars($brand_email) ?></p>
                            <p class="text-gray-500 text-sm"><?= htmlspecialchars($brand_copyright) ?></p>
                        </div>
                    </div>
                </div>
            </footer>

        </div>
    </script>
    <script>
        window.APP_CONFIG = {
            apiUrl: <?= json_encode($api_url) ?>,
            baseUrl: <?= json_encode($base_url) ?>,
            logoUrl: <?= json_encode($logo_url) ?>,
            afiliadosPortalBase: <?= json_encode($base_url . 'landing-afiliados.php') ?>,
            showAfiliadosHub: <?= json_encode($show_afiliados_section) ?>,
            siteName: <?= json_encode($brand_name) ?>,
            siteTagline: <?= json_encode($brand_tagline) ?>,
            contactEmail: <?= json_encode($brand_email) ?>
        };
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="<?= htmlspecialchars($base_url . 'assets/landing-spa.js?v=' . (@filemtime(__DIR__ . '/assets/landing-spa.js') ?: time())) ?>"></script>
</body>
</html>
