/**
 * Configuración de la aplicación para JavaScript
 * Los valores por defecto se sobreescriben desde PHP (window.APP_CONFIG).
 */
const APP_CONFIG = window.APP_CONFIG || {
    publicPath: '/mistorneos_fvd/public/',
    apiPath: '/mistorneos_fvd/public/api/',
    isProduction: false
};

function apiUrl(endpoint) {
    endpoint = endpoint.replace(/^\//, '');
    return APP_CONFIG.apiPath + endpoint;
}

function publicUrl(path) {
    path = path.replace(/^\//, '');
    return APP_CONFIG.publicPath + path;
}

window.apiUrl = apiUrl;
window.publicUrl = publicUrl;
