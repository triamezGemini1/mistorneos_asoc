<?php
/**
 * Componente Hero - Sección principal de bienvenida (layout compacto)
 * Variables globales disponibles: $user, app_base_url(), $SITE_NAME (config.php)
 */
if (! isset($brand_name)) {
    if (! class_exists('Branding', false)) {
        $brandingPath = dirname(__DIR__, 2) . '/lib/Branding.php';
        if (is_file($brandingPath)) {
            require_once dirname(__DIR__, 2) . '/lib/Env.php';
            require_once dirname(__DIR__, 2) . '/lib/SegmentConfig.php';
            require_once $brandingPath;
            SegmentConfig::boot();
        }
    }
    $brand_name = class_exists('Branding', false) ? Branding::siteName() : ($SITE_NAME ?? 'La Estación del Dominó');
}
$hero_logo_url = class_exists('Branding', false)
    ? Branding::logoUrl()
    : (class_exists('AppHelpers') ? AppHelpers::getAppLogo() : (rtrim(app_base_url(), '/') . '/public/view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png')));
$hero_tagline = class_exists('Branding', false) ? Branding::tagline() : ($SITE_TAGLINE ?? 'La plataforma integral para la gestión de torneos de dominó en Venezuela.');
?>
    <!-- Hero Section (compacto, dos columnas) -->
    <section class="relative bg-gradient-to-br from-primary-700 via-primary-600 to-primary-500 text-white overflow-hidden min-h-[100vh] flex items-center">
        <!-- Background Pattern -->
        <div class="absolute inset-0 opacity-10">
            <div class="absolute inset-0" style="background-image: radial-gradient(circle at 2px 2px, white 1px, transparent 0); background-size: 40px 40px;"></div>
        </div>
        <div class="absolute inset-0 bg-gradient-to-r from-accent/20 via-transparent to-blue-500/20"></div>
        
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8 md:py-12 relative z-10 w-full">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12 items-center min-h-[calc(100vh-8rem)]">
                <!-- Columna izquierda: Texto y CTA -->
                <div class="order-2 lg:order-1 text-center lg:text-left">
                    <h1 class="text-3xl sm:text-4xl md:text-5xl font-bold mb-4 leading-tight">
                        Bienvenido a<br>
                        <span class="text-accent"><?= htmlspecialchars($brand_name) ?></span>
                    </h1>
                    <p class="text-base md:text-lg lg:text-xl mb-6 text-white/90 leading-relaxed max-w-xl mx-auto lg:mx-0">
                        <?= htmlspecialchars($hero_tagline) ?>
                        Participa en torneos de tu asociación o únete como organizador.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-3 justify-center lg:justify-start">
                        <a href="#registro" class="inline-flex items-center justify-center px-6 py-3 bg-accent text-primary-700 font-semibold rounded-xl hover:bg-accentDark hover:text-white transition-all duration-300 shadow-lg hover:shadow-xl">
                            <i class="fas fa-building mr-2"></i>Solicitar Afiliación
                        </a>
                        <a href="login.php" class="inline-flex items-center justify-center px-6 py-3 bg-white/10 backdrop-blur-sm text-white font-semibold rounded-xl border-2 border-white/30 hover:bg-white hover:text-primary-700 transition-all duration-300">
                            <i class="fas fa-sign-in-alt mr-2"></i>Ya tengo cuenta
                        </a>
                    </div>
                </div>
                <!-- Columna derecha: Imagen/Media -->
                <div class="order-1 lg:order-2 flex justify-center lg:justify-end">
                    <div class="relative w-48 h-48 sm:w-64 sm:h-64 md:w-80 md:h-80 lg:w-96 lg:h-96 flex items-center justify-center">
                        <div class="absolute inset-0 bg-white/10 rounded-3xl blur-2xl"></div>
                        <img src="<?= htmlspecialchars($hero_logo_url) ?>" 
                             alt="<?= htmlspecialchars($brand_name) ?>" 
                             class="relative w-full h-full object-contain drop-shadow-2xl">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Wave Divider -->
        <div class="absolute bottom-0 left-0 right-0">
            <svg class="w-full h-12 md:h-16" viewBox="0 0 1200 120" preserveAspectRatio="none">
                <path d="M0,0 C150,80 350,80 600,40 C850,0 1050,0 1200,40 L1200,120 L0,120 Z" fill="#f9fafb"></path>
            </svg>
        </div>
    </section>
