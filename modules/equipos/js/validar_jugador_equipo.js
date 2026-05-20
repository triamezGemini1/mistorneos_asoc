/**
 * Validación en tiempo real de jugadores para equipos
 * 
 * Este script valida al introducir la cédula/carnet/id_usuario
 * si el jugador ya está registrado en otro equipo del torneo.
 * 
 * USO:
 * 1. Incluir este script en la página
 * 2. Llamar a initValidacionEquipo(torneoId) al cargar la página
 * 3. Los campos de cédula deben tener la clase 'cedula-jugador' o data-validar-equipo="true"
 */

const ValidadorJugadorEquipo = {
    torneoId: null,
    equipoIdActual: null, // Para excluir al editar un equipo existente
    apiUrl: '/mistorneos_fvd/public/api/verificar_jugador_equipo.php',
    
    /**
     * Inicializar el validador
     * @param {number} torneoId - ID del torneo
     * @param {number|null} equipoIdActual - ID del equipo actual (para edición)
     */
    init: function(torneoId, equipoIdActual = null) {
        this.torneoId = torneoId;
        this.equipoIdActual = equipoIdActual;
        this.bindEvents();
        console.log('✅ ValidadorJugadorEquipo inicializado para torneo:', torneoId);
    },
    
    /**
     * Enlazar eventos a los campos de cédula
     */
    bindEvents: function() {
        // Buscar todos los campos que deben validarse
        const campos = document.querySelectorAll('.cedula-jugador, [data-validar-equipo="true"]');
        
        campos.forEach(campo => {
            // Validar al perder el foco
            campo.addEventListener('blur', (e) => this.validarCampo(e.target));
            
            // Validar al presionar Enter
            campo.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.validarCampo(e.target);
                }
            });
        });
    },
    
    /**
     * Validar un campo específico
     * @param {HTMLElement} campo - El campo input a validar
     */
    validarCampo: async function(campo) {
        const valor = campo.value.trim();
        
        if (!valor) {
            this.limpiarEstado(campo);
            return;
        }
        
        // Determinar si es cédula o id_usuario
        const esCedula = isNaN(valor) || valor.length > 10 || /^[VEJP]/i.test(valor);
        const params = {
            torneo_id: this.torneoId
        };
        
        if (campo.dataset.tipoValidacion === 'id_usuario' || campo.dataset.tipoValidacion === 'carnet') {
            params.id_usuario = valor;
        } else {
            params.cedula = valor;
        }
        
        if (this.equipoIdActual) {
            params.equipo_id = this.equipoIdActual;
        }
        
        // Mostrar estado de carga
        this.mostrarCargando(campo);
        
        try {
            const resultado = await this.verificarJugador(params);
            this.mostrarResultado(campo, resultado);
        } catch (error) {
            console.error('Error al validar jugador:', error);
            this.mostrarError(campo, 'Error de conexión');
        }
    },
    
    /**
     * Llamar a la API para verificar el jugador
     * @param {Object} params - Parámetros de búsqueda
     * @returns {Promise<Object>}
     */
    verificarJugador: async function(params) {
        const queryString = new URLSearchParams(params).toString();
        const response = await fetch(`${this.apiUrl}?${queryString}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    },
    
    /**
     * Mostrar estado de carga en el campo
     */
    mostrarCargando: function(campo) {
        campo.classList.remove('is-valid', 'is-invalid');
        campo.classList.add('is-loading');
        
        const feedback = this.getFeedbackElement(campo);
        if (feedback) {
            feedback.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verificando...';
            feedback.className = 'form-text text-muted';
        }
    },
    
    /**
     * Mostrar resultado de la validación
     */
    mostrarResultado: function(campo, resultado) {
        campo.classList.remove('is-loading');
        
        const feedback = this.getFeedbackElement(campo);
        const nombreField = this.getNombreField(campo);
        
        if (resultado.disponible) {
            // Jugador disponible ✅
            campo.classList.remove('is-invalid');
            campo.classList.add('is-valid');
            
            if (feedback) {
                if (resultado.jugador) {
                    feedback.innerHTML = `✅ ${resultado.jugador.nombre} - Disponible`;
                    feedback.className = 'valid-feedback d-block';
                    
                    // Auto-rellenar nombre si existe el campo
                    if (nombreField) {
                        nombreField.value = resultado.jugador.nombre;
                        nombreField.readOnly = true;
                    }
                } else {
                    feedback.innerHTML = '✅ Cédula disponible (jugador no registrado)';
                    feedback.className = 'form-text text-warning';
                    
                    // Habilitar campo de nombre para ingreso manual
                    if (nombreField) {
                        nombreField.readOnly = false;
                        nombreField.focus();
                    }
                }
            }
            
            // Disparar evento personalizado
            campo.dispatchEvent(new CustomEvent('jugador-disponible', { 
                detail: resultado 
            }));
            
        } else {
            // Jugador NO disponible ❌
            campo.classList.remove('is-valid');
            campo.classList.add('is-invalid');
            
            if (feedback) {
                feedback.innerHTML = `❌ ${resultado.mensaje}`;
                feedback.className = 'invalid-feedback d-block';
            }
            
            // Limpiar campo de nombre
            if (nombreField) {
                nombreField.value = '';
                nombreField.readOnly = true;
            }
            
            // Disparar evento personalizado
            campo.dispatchEvent(new CustomEvent('jugador-no-disponible', { 
                detail: resultado 
            }));
        }
    },
    
    /**
     * Mostrar error en el campo
     */
    mostrarError: function(campo, mensaje) {
        campo.classList.remove('is-loading', 'is-valid');
        campo.classList.add('is-invalid');
        
        const feedback = this.getFeedbackElement(campo);
        if (feedback) {
            feedback.innerHTML = `⚠️ ${mensaje}`;
            feedback.className = 'invalid-feedback d-block';
        }
    },
    
    /**
     * Limpiar estado del campo
     */
    limpiarEstado: function(campo) {
        campo.classList.remove('is-loading', 'is-valid', 'is-invalid');
        
        const feedback = this.getFeedbackElement(campo);
        if (feedback) {
            feedback.innerHTML = '';
            feedback.className = 'form-text';
        }
    },
    
    /**
     * Obtener o crear el elemento de feedback
     */
    getFeedbackElement: function(campo) {
        let feedback = campo.parentElement.querySelector('.jugador-feedback');
        
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'jugador-feedback form-text';
            campo.parentElement.appendChild(feedback);
        }
        
        return feedback;
    },
    
    /**
     * Obtener el campo de nombre asociado
     */
    getNombreField: function(campo) {
        // Buscar por data attribute
        if (campo.dataset.nombreField) {
            return document.getElementById(campo.dataset.nombreField);
        }
        
        // Buscar en el mismo contenedor
        const container = campo.closest('.jugador-row, .jugador-container, .mb-3');
        if (container) {
            return container.querySelector('[name*="nombre"], .nombre-jugador');
        }
        
        return null;
    },
    
    /**
     * Validar todos los campos de jugadores (útil antes de enviar formulario)
     * @returns {Promise<boolean>} true si todos los jugadores están disponibles
     */
    validarTodos: async function() {
        const campos = document.querySelectorAll('.cedula-jugador, [data-validar-equipo="true"]');
        let todosValidos = true;
        
        for (const campo of campos) {
            if (campo.value.trim()) {
                await this.validarCampo(campo);
                
                if (campo.classList.contains('is-invalid')) {
                    todosValidos = false;
                }
            }
        }
        
        return todosValidos;
    }
};


/**
 * Función de inicialización simplificada
 */
function initValidacionEquipo(torneoId, equipoIdActual = null) {
    ValidadorJugadorEquipo.init(torneoId, equipoIdActual);
}


/**
 * Validación individual (útil para llamadas manuales)
 * @param {string} cedula - Cédula a validar
 * @param {number} torneoId - ID del torneo
 * @param {number|null} equipoIdExcluir - ID del equipo a excluir
 * @returns {Promise<Object>}
 */
async function verificarJugadorEquipo(cedula, torneoId, equipoIdExcluir = null) {
    const params = {
        torneo_id: torneoId,
        cedula: cedula
    };
    
    if (equipoIdExcluir) {
        params.equipo_id = equipoIdExcluir;
    }
    
    const queryString = new URLSearchParams(params).toString();
    const response = await fetch(`${window.APP_CONFIG?.apiPath || '/mistorneos_fvd/public/api/'}verificar_jugador_equipo.php?${queryString}`);
    
    return await response.json();
}









