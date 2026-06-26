/**

 * Landing SPA - Vue 3 Application

 * Mejora UX con navegación fluida, transiciones y carga dinámica

 */



const { createApp, ref, computed, onMounted } = Vue;



const MODALIDADES = { 1: 'Individual', 2: 'Parejas', 3: 'Equipos' };

const CLASES = { 1: 'Torneo', 2: 'Campeonato' };



function escapeHtml(s) {

    if (!s) return '';

    const d = document.createElement('div');

    d.textContent = s;

    return d.innerHTML;

}



function formatFecha(fechator) {

    return new Date(fechator).toLocaleDateString('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric' });

}



function esHoy(fechator) {

    if (!fechator) return false;

    const today = new Date().toISOString().slice(0, 10);

    const fechaEv = String(fechator).slice(0, 10);

    return fechaEv === today;

}



const LandingContent = {

    name: 'LandingContent',

    props: ['data', 'baseUrl', 'logoUrl'],

    emits: ['refresh-comentarios'],

    setup(props, { emit }) {

        const mobileMenuOpen = ref(false);

        const commentSending = ref(false);

        const commentSuccess = ref(null);

        const commentErrors = ref([]);

        const commentForm = ref({ tipo: 'comentario', contenido: '', calificacion: null });



        const afiliadoPortalUrl = (orgId) => {

            const base = window.APP_CONFIG?.afiliadosPortalBase || (props.baseUrl + 'landing-afiliados.php');

            return base.replace(/#.*$/, '') + '#/a/' + orgId;

        };

        const showAfiliadosHub = computed(() => {
            if (props.data?.show_afiliados_hub !== undefined) {
                return !!props.data.show_afiliados_hub;
            }
            return !!window.APP_CONFIG?.showAfiliadosHub;
        });

        const documentosDescarga = computed(() => {
            if (Array.isArray(props.data?.documentos_descarga) && props.data.documentos_descarga.length) {
                return props.data.documentos_descarga;
            }
            const oficiales = props.data?.documentos_oficiales || [];
            const invitaciones = props.data?.invitaciones_fvd || [];
            return [...oficiales, ...invitaciones];
        });

        const docIconClass = (doc) => {
            const path = String(doc?.path || '').toLowerCase();
            if (/\.(png|jpe?g|gif|webp)$/.test(path)) {
                return 'landing-doc-card__icon--img';
            }
            return 'landing-doc-card__icon--pdf';
        };

        const docIconName = (doc) => {
            const path = String(doc?.path || '').toLowerCase();
            if (/\.(png|jpe?g|gif|webp)$/.test(path)) {
                return 'fas fa-image';
            }
            return 'fas fa-file-pdf';
        };



        const scrollToSection = (id) => {

            mobileMenuOpen.value = false;

            const el = document.getElementById(id);

            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });

        };



        const enviarComentario = async () => {

            commentErrors.value = [];

            commentSuccess.value = null;

            if (!commentForm.value.contenido || commentForm.value.contenido.trim().length < 10) {

                commentErrors.value = ['El comentario debe tener al menos 10 caracteres'];

                return;

            }

            commentSending.value = true;

            try {

                const res = await fetch(`${props.baseUrl}api/comentarios_save.php`, {

                    method: 'POST',

                    headers: {

                        'Content-Type': 'application/json',

                        'X-CSRF-Token': props.data.csrf_token

                    },

                    body: JSON.stringify({

                        tipo: commentForm.value.tipo,

                        contenido: commentForm.value.contenido.trim(),

                        calificacion: commentForm.value.calificacion || null

                    })

                });

                const data = await res.json();

                if (data.success) {

                    commentSuccess.value = data.message;

                    commentForm.value = { tipo: 'comentario', contenido: '', calificacion: null };

                    emit('refresh-comentarios');

                } else {

                    commentErrors.value = data.errors || [data.error || 'Error al enviar'];

                }

            } catch (err) {

                commentErrors.value = ['Error de conexión. Intenta de nuevo.'];

            } finally {

                commentSending.value = false;

            }

        };



        return {

            mobileMenuOpen,

            commentForm,

            commentSending,

            commentSuccess,

            commentErrors,

            scrollToSection,

            enviarComentario,

            MODALIDADES,

            CLASES,

            formatFecha,

            escapeHtml,

            esHoy,

            afiliadoPortalUrl,

            showAfiliadosHub,

            documentosDescarga,

            docIconClass,

            docIconName

        };

    },

    template: `#landing-template`

};



createApp({

    setup() {

        const loading = ref(true);

        const error = ref(null);

        const data = ref(null);

        const baseUrl = ref(window.APP_CONFIG?.baseUrl || '');



        const cargarDatos = async () => {

            loading.value = true;

            error.value = null;

            try {

                const res = await fetch(window.APP_CONFIG?.apiUrl || 'api/landing_data.php');

                const json = await res.json();

                if (json.success) {

                    data.value = json;

                    baseUrl.value = json.base_url || baseUrl.value;

                } else {

                    error.value = json.message || json.error || 'Error al cargar los datos';

                }

            } catch (err) {

                error.value = 'No se pudo conectar a la API. ' + (err.message || 'Verifica tu conexión.');

            } finally {

                loading.value = false;

            }

        };



        const logoUrl = ref(window.APP_CONFIG?.logoUrl || '');

        const effectiveLogoUrl = computed(() => {

            if (logoUrl.value) return logoUrl.value;

            const b = (baseUrl.value || '').replace(/\/$/, '');

            return b ? b + '/view_image.php?path=' + encodeURIComponent('lib/Assets/mislogos/logo4.png') : '';

        });



        onMounted(() => {

            if (window.APP_CONFIG?.logoUrl) logoUrl.value = window.APP_CONFIG.logoUrl;

            cargarDatos();

        });



        return { loading, error, data, baseUrl, logoUrl: effectiveLogoUrl, cargarDatos };

    },

    components: { LandingContent }

}).mount('#app');


