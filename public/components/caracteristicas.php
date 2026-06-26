<?php
/**
 * Componente Características - ¿Qué Ofrecemos? / Servicios
 * Variables globales disponibles: $user, app_base_url()
 */
?>
    <!-- ¿Qué Ofrecemos? Section (Características) -->
    <section id="servicios" class="py-16 md:py-24 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-primary-700 mb-4">
                    ¿Qué Ofrecemos?
                </h2>
                <p class="text-lg md:text-xl text-gray-600 max-w-2xl mx-auto">
                    Todo lo que necesitas para disfrutar del dominó de manera profesional y organizada
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                <!-- Servicio 1 -->
                <div class="group bg-gradient-to-br from-blue-50 to-indigo-100 rounded-2xl p-8 hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2 border border-blue-100">
                    <div class="bg-primary-500 w-16 h-16 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg">
                        <i class="fas fa-trophy text-white text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-3">Gestión de Torneos</h3>
                    <p class="text-gray-600 mb-4">
                        Sistema completo para organizar, administrar y seguir torneos de dominó con clasificaciones en tiempo real
                    </p>
                    <a href="landing-afiliados.php" class="text-primary-600 font-semibold hover:text-primary-800 inline-flex items-center transition-colors">
                        Ver asociaciones <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <!-- Servicio 2 -->
                <div class="group bg-gradient-to-br from-purple-50 to-pink-100 rounded-2xl p-8 hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2 border border-purple-100">
                    <div class="bg-purple-600 w-16 h-16 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg">
                        <i class="fas fa-users text-white text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-3">Clubes Registrados</h3>
                    <p class="text-gray-600 mb-4">
                        Conoce todos los clubes afiliados, sus directivos y cómo contactarlos para participar en sus actividades
                    </p>
                    <a href="#registro" class="text-purple-600 font-semibold hover:text-purple-800 inline-flex items-center transition-colors">
                        Explorar Clubes <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <!-- Servicio 3 -->
                <div class="group bg-gradient-to-br from-green-50 to-emerald-100 rounded-2xl p-8 hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2 border border-green-100">
                    <div class="bg-accent w-16 h-16 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg">
                        <i class="fas fa-calendar-alt text-white text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-3">Calendario Anual</h3>
                    <p class="text-gray-600 mb-4">
                        Mantente informado de todos los eventos, torneos, reuniones y actividades durante todo el año
                    </p>
                    <a href="#calendario" class="text-accent font-semibold hover:text-accentDark inline-flex items-center transition-colors">
                        Ver Calendario <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <!-- Servicio 4 -->
                <div class="group bg-gradient-to-br from-yellow-50 to-orange-100 rounded-2xl p-8 hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2 border border-yellow-100">
                    <div class="bg-yellow-500 w-16 h-16 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg">
                        <i class="fas fa-chart-line text-white text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-3">Resultados en Vivo</h3>
                    <p class="text-gray-600 mb-4">
                        Consulta resultados de torneos realizados, estadísticas de jugadores y rankings actualizados
                    </p>
                    <a href="landing-afiliados.php" class="text-yellow-600 font-semibold hover:text-yellow-800 inline-flex items-center transition-colors">
                        Ver asociaciones <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <!-- Servicio 5 -->
                <div class="group bg-gradient-to-br from-red-50 to-rose-100 rounded-2xl p-8 hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2 border border-red-100">
                    <div class="bg-red-500 w-16 h-16 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg">
                        <i class="fas fa-id-card text-white text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-3">Credencial Digital</h3>
                    <p class="text-gray-600 mb-4">
                        Obtén tu credencial única con código QR para identificarte en cualquier evento
                    </p>
                    <a href="#registro" class="text-red-600 font-semibold hover:text-red-800 inline-flex items-center transition-colors">
                        Crear Perfil <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <!-- Servicio 6 -->
                <div class="group bg-gradient-to-br from-cyan-50 to-blue-100 rounded-2xl p-8 hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2 border border-cyan-100">
                    <div class="bg-cyan-500 w-16 h-16 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform shadow-lg">
                        <i class="fas fa-mobile-alt text-white text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-3">Acceso Móvil</h3>
                    <p class="text-gray-600 mb-4">
                        Accede desde cualquier dispositivo con nuestra plataforma responsive y optimizada
                    </p>
                    <a href="#registro" class="text-cyan-600 font-semibold hover:text-cyan-800 inline-flex items-center transition-colors">
                        Comenzar Ahora <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>
