<?php
/**
 * Helper para manejo de estatus de inscritos
 *
 * Solo tres estatus en uso:
 * - pendiente: inscrito en línea sin confirmación de pago
 * - confirmado: inscrito en línea con pago verificado, o inscripción en sitio
 * - retirado: retirado del torneo
 *
 * Los valores legacy 'solvente' y 'no_solvente' se obvian; migrar a 'confirmado' si existen.
 */

class InscritosHelper {

    /** Estatus vigentes (uso en SQL y persistencia) */
    const ESTATUS_PENDIENTE = 'pendiente';
    const ESTATUS_CONFIRMADO = 'confirmado';
    const ESTATUS_RETIRADO = 'retirado';

    /** Valor numérico para confirmado (columna INT en producción). */
    const ESTATUS_CONFIRMADO_NUM = 1;

    /** Valor numérico para retirado (columna INT). */
    const ESTATUS_RETIRADO_NUM = 4;

    /**
     * Condición SQL: solo inscritos confirmados (cuentan para participar en el torneo).
     * Compatible con columna INT y ENUM.
     */
    const SQL_WHERE_SOLO_CONFIRMADO = "(estatus = 1 OR estatus = 'confirmado')";

    /**
     * Misma condición que SQL_WHERE_SOLO_CONFIRMADO con alias de tabla.
     */
    public static function sqlWhereSoloConfirmadoConAlias($alias = '')
    {
        $e = $alias ? $alias . '.' : '';
        return "(" . $e . "estatus = 1 OR " . $e . "estatus = 'confirmado')";
    }

    /**
     * Condición SQL: inscrito activo (no retirado). Usar en WHERE.
     * Compatible con columna INT (retirado = 4) y VARCHAR/ENUM ('retirado').
     * CAST evita 1292 en MySQL estricto: no comparar literal 'retirado' contra INT.
     * Con estatus NULL, CAST da NULL y NOT IN no coincide (mismo efecto práctico que el != anterior).
     */
    const SQL_WHERE_NO_RETIRADO = "(CAST(estatus AS CHAR) NOT IN ('4', 'retirado'))";

    /**
     * Condición SQL: inscrito activo para conteo y rondas (no retirado).
     * CAST(... AS CHAR) evita 1292 al mezclar INT con literales 'pendiente' (alineado con lib/InscritosHelper).
     */
    const SQL_WHERE_ACTIVO = "(CAST(estatus AS CHAR) IN ('0','1','2','3','pendiente','confirmado','solvente','no_solvente'))";

    /**
     * Misma condición que SQL_WHERE_ACTIVO con alias de tabla (ej: 'i' → "i.estatus ...").
     * @param string $alias Alias de la tabla inscritos (ej: 'i'). Vacío = sin alias.
     */
    public static function sqlWhereActivoConAlias($alias = '')
    {
        $e = $alias ? $alias . '.' : '';
        return '(CAST(' . $e . 'estatus AS CHAR) IN (\'0\',\'1\',\'2\',\'3\',\'pendiente\',\'confirmado\',\'solvente\',\'no_solvente\'))';
    }

    /**
     * Alineado con lib/InscritosHelper: columna numérica segura en SQL (evita 1292 con texto en DOUBLE).
     */
    public static function sqlExprColumnaNumerica(string $col): string
    {
        if ($col === '' || !preg_match('/^[a-zA-Z0-9_.]+$/', $col)) {
            throw new InvalidArgumentException('sqlExprColumnaNumerica: columna inválida');
        }
        return 'IF(CAST(' . $col . ' AS CHAR) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\', CAST(' . $col . ' AS DECIMAL(18,4)), 0)';
    }

    /**
     * partiresul: comparación segura resultado1 > resultado2 (misma lógica que torneo web).
     */
    public static function sqlExprPartiresulResultado1MayorQueResultado2(string $alias = 'pr'): string
    {
        if ($alias === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('sqlExprPartiresulResultado1MayorQueResultado2: alias inválido');
        }
        $r1 = self::sqlExprColumnaNumerica($alias . '.resultado1');
        $r2 = self::sqlExprColumnaNumerica($alias . '.resultado2');

        return "(({$r1}) > ({$r2}))";
    }

    /**
     * Condición SQL: inscrito confirmado (cuenta para rondas: pago verificado o inscripción en sitio).
     */
    const SQL_WHERE_CONFIRMADO = "estatus = 'confirmado'";

    /**
     * Mapeo numérico (API/legacy) a enum. Solo 0, 1, 4 en uso.
     */
    const ESTATUS_MAP = [
        0 => 'pendiente',
        1 => 'confirmado',
        4 => 'retirado'
    ];

    /** Para lectura legacy: solvente/no_solvente se muestran como confirmado */
    const ESTATUS_MAP_LEGACY = [
        2 => 'confirmado',
        3 => 'confirmado'
    ];

    /**
     * Mapeo inverso: texto a número
     */
    const ESTATUS_REVERSE_MAP = [
        'pendiente' => 0,
        'confirmado' => 1,
        'retirado' => 4
    ];
    
    /**
     * Obtiene el texto del estatus a partir del valor numérico
     * 
     * @param int $estatus_num Valor numérico del estatus
     * @return string Texto del estatus o 'desconocido' si no existe
     */
    public static function getEstatusTexto(int $estatus_num): string {
        return self::ESTATUS_MAP[$estatus_num] ?? self::ESTATUS_MAP_LEGACY[$estatus_num] ?? 'desconocido';
    }
    
    /**
     * Obtiene el valor numérico del estatus a partir del texto
     * 
     * @param string $estatus_texto Texto del estatus
     * @return int Valor numérico del estatus o 0 (pendiente) por defecto
     */
    public static function getEstatusNumero(string $estatus_texto): int {
        if (isset(self::ESTATUS_REVERSE_MAP[$estatus_texto])) {
            return self::ESTATUS_REVERSE_MAP[$estatus_texto];
        }
        if (in_array($estatus_texto, ['solvente', 'no_solvente'], true)) {
            return 1;
        }
        return 0;
    }
    
    /**
     * Obtiene todos los estatus disponibles como array [numero => texto]
     * 
     * @return array Array asociativo con número => texto
     */
    public static function getEstatusOptions(): array {
        return self::ESTATUS_MAP;
    }
    
    /**
     * Obtiene todos los estatus disponibles para usar en formularios HTML
     * 
     * @return array Array con formato ['value' => numero, 'text' => texto, 'label' => texto_formateado]
     */
    /** Solo los 3 estatus vigentes para formularios */
    public static function getEstatusFormOptions(): array {
        $options = [];
        foreach (self::ESTATUS_MAP as $num => $texto) {
            $options[] = [
                'value' => $num,
                'text' => $texto,
                'label' => ucfirst(str_replace('_', ' ', $texto))
            ];
        }
        return $options;
    }
    
    /**
     * Valida si un valor numérico de estatus es válido
     * 
     * @param int $estatus_num Valor numérico a validar
     * @return bool True si es válido, False si no
     */
    public static function isValidEstatus(int $estatus_num): bool {
        return isset(self::ESTATUS_MAP[$estatus_num]);
    }

    /** Indica si el valor (string o int) es un estatus confirmado (puede jugar). */
    public static function esConfirmado($estatus): bool {
        if (is_numeric($estatus)) {
            return (int)$estatus === 1;
        }
        return $estatus === self::ESTATUS_CONFIRMADO || $estatus === 'solvente';
    }
    
    /**
     * Obtiene el texto formateado del estatus (con mayúsculas y espacios)
     * 
     * @param int $estatus_num Valor numérico del estatus
     * @return string Texto formateado (ej: "No Solvente")
     */
    public static function getEstatusFormateado(int $estatus_num): string {
        $texto = self::getEstatusTexto($estatus_num);
        return ucfirst(str_replace('_', ' ', $texto));
    }
    
    /**
     * Obtiene la clase CSS para el estatus (útil para badges)
     * 
     * @param int $estatus_num Valor numérico del estatus
     * @return string Clase CSS (ej: "badge-warning", "badge-success")
     */
    public static function getEstatusClaseCSS(int $estatus_num): string {
        $clases = [
            0 => 'bg-secondary text-white',  // pendiente
            1 => 'bg-success text-white',   // confirmado
            4 => 'bg-dark text-white'        // retirado
        ];
        return $clases[$estatus_num] ?? $clases[0];
    }
    
    /**
     * Convierte un array de inscritos agregando el campo estatus_texto
     * 
     * @param array $inscritos Array de inscritos con estatus numérico
     * @return array Array con campo adicional estatus_texto
     */
    public static function agregarEstatusTexto(array $inscritos): array {
        return array_map(function($inscrito) {
            if (isset($inscrito['estatus'])) {
                $num = is_numeric($inscrito['estatus']) ? (int)$inscrito['estatus'] : self::getEstatusNumero((string)$inscrito['estatus']);
                $inscrito['estatus_texto'] = self::getEstatusTexto($num);
                $inscrito['estatus_formateado'] = self::getEstatusFormateado($num);
                $inscrito['estatus_clase'] = self::getEstatusClaseCSS($num);
            }
            return $inscrito;
        }, $inscritos);
    }
    
    /**
     * Función centralizada para insertar inscripción en tabla inscritos
     * Valida todos los campos obligatorios y asegura que todos los campos tengan valores
     * 
     * @param PDO $pdo Conexión a la base de datos
     * @param array $datos Datos de la inscripción:
     *   - id_usuario (int, requerido)
     *   - torneo_id (int, requerido)
     *   - id_club (int|null, opcional)
     *   - estatus (int, opcional, default=1)
     *   - inscrito_por (int|null, opcional)
     *   - numero (int|null, opcional, default=0)
     *   - codigo_equipo (string|null, opcional)
     * @return int ID del registro insertado
     * @throws Exception Si los datos son inválidos o hay error al insertar
     */
    public static function insertarInscrito(PDO $pdo, array $datos): int {
        // Validar campos obligatorios
        $id_usuario = (int)($datos['id_usuario'] ?? 0);
        $torneo_id = (int)($datos['torneo_id'] ?? 0);
        
        if ($id_usuario <= 0) {
            throw new Exception('ID de usuario es requerido y debe ser mayor a 0');
        }
        
        if ($torneo_id <= 0) {
            throw new Exception('ID de torneo es requerido y debe ser mayor a 0');
        }
        
        // Validar estatus: solo pendiente, confirmado, retirado
        $estatusRaw = $datos['estatus'] ?? 1;
        if (is_numeric($estatusRaw)) {
            $estatusNum = (int)$estatusRaw;
            $estatus = self::ESTATUS_MAP[$estatusNum] ?? self::ESTATUS_CONFIRMADO;
        } else {
            $estatus = in_array($estatusRaw, [self::ESTATUS_PENDIENTE, self::ESTATUS_CONFIRMADO, self::ESTATUS_RETIRADO], true)
                ? $estatusRaw : self::ESTATUS_CONFIRMADO;
        }
        
        // Campos opcionales con valores por defecto
        $id_club = !empty($datos['id_club']) ? (int)$datos['id_club'] : null;
        $inscrito_por = !empty($datos['inscrito_por']) ? (int)$datos['inscrito_por'] : null;
        // numero: Si no se especifica, usar 0 (no NULL) para evitar constraint violation
        // El campo numero se asigna después en equipos, pero debe tener un valor inicial
        $numero = isset($datos['numero']) && $datos['numero'] !== null ? (int)$datos['numero'] : 0;
        // clasiequi: Clasificación de equipo (INT), valor por defecto 0
        $clasiequi = isset($datos['clasiequi']) && $datos['clasiequi'] !== null ? (int)$datos['clasiequi'] : 0;
        $codigo_equipo = !empty($datos['codigo_equipo']) ? trim($datos['codigo_equipo']) : null;
        
        $entidad_id = (int)(isset($datos['entidad_id']) ? $datos['entidad_id'] : (class_exists('DB', false) ? DB::getEntidadId() : (defined('DESKTOP_ENTIDAD_ID') ? DESKTOP_ENTIDAD_ID : 0)));
        $stmt = $pdo->prepare("SELECT id FROM inscritos WHERE id_usuario = ? AND torneo_id = ? AND " . self::SQL_WHERE_NO_RETIRADO . ($entidad_id > 0 ? " AND entidad_id = ?" : ""));
        $stmt->execute($entidad_id > 0 ? [$id_usuario, $torneo_id, $entidad_id] : [$id_usuario, $torneo_id]);
        if ($stmt->fetch()) {
            throw new Exception('Este usuario ya está inscrito en el torneo');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO inscritos (
                id_usuario, torneo_id, id_club, estatus, inscrito_por, fecha_inscripcion,
                posicion, ganados, perdidos, efectividad, puntos, ptosrnk, 
                sancion, chancletas, zapatos, tarjeta, numero, clasiequi, entidad_id" . 
                ($codigo_equipo !== null ? ", codigo_equipo" : "") . "
            ) VALUES (?, ?, ?, ?, ?, NOW(), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, ?, ?, ?" .
                ($codigo_equipo !== null ? ", ?" : "") . "
            )
        ");
        
        $estatus_for_db = self::getEstatusNumero($estatus);
        $params = [$id_usuario, $torneo_id, $id_club, $estatus_for_db, $inscrito_por, $numero, $clasiequi, $entidad_id];
        if ($codigo_equipo !== null) {
            $params[] = $codigo_equipo;
        }
        
        $resultado = $stmt->execute($params);
        
        if (!$resultado) {
            $error_info = $stmt->errorInfo();
            throw new Exception('Error al insertar la inscripción: ' . ($error_info[2] ?? 'Error desconocido'));
        }
        
        return (int)$pdo->lastInsertId();
    }
    
    /**
     * Verifica si un usuario puede inscribirse en línea en eventos masivos (es_evento_masivo = 2)
     * 
     * Un usuario NO puede inscribirse en línea si:
     * - Tiene 2 o más inscripciones consecutivas previas en eventos con es_evento_masivo = 2
     * - En esas inscripciones no asistió al evento (ganados = 0 AND perdidos = 0)
     * 
     * NOTA: El pago NO es obligatorio para inscribirse en línea, solo se verifica la asistencia.
     * 
     * @param PDO $pdo Conexión a la base de datos
     * @param int $id_usuario ID del usuario
     * @return array ['puede_inscribirse' => bool, 'razon' => string|null]
     */
    public static function puedeInscribirseEnLinea(PDO $pdo, int $id_usuario): array {
        try {
            // Buscar inscripciones previas en eventos masivos tipo 2 (inscripción en línea de administradores)
            // Ordenadas por fecha descendente para verificar las más recientes primero
            $stmt = $pdo->prepare("
                SELECT 
                    i.id,
                    i.estatus,
                    i.ganados,
                    i.perdidos,
                    t.fechator,
                    t.costo,
                    t.nombre as torneo_nombre
                FROM inscritos i
                INNER JOIN tournaments t ON i.torneo_id = t.id
                WHERE i.id_usuario = ?
                  AND t.es_evento_masivo IN (2, 3)
                  AND t.fechator < CURDATE()
                ORDER BY t.fechator DESC
            ");
            $stmt->execute([$id_usuario]);
            $inscripciones_previas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Contar no presentaciones consecutivas (más recientes primero)
            $no_presentaciones_consecutivas = 0;
            $torneos_problema = [];
            
            foreach ($inscripciones_previas as $inscripcion) {
                $ganados = (int)$inscripcion['ganados'];
                $perdidos = (int)$inscripcion['perdidos'];
                
                // Verificar si NO asistió (no jugó ninguna partida)
                $no_asistio = ($ganados == 0 && $perdidos == 0);
                
                if ($no_asistio) {
                    // Si no asistió, incrementar contador de no presentaciones consecutivas
                    $no_presentaciones_consecutivas++;
                    $torneos_problema[] = $inscripcion['torneo_nombre'];
                } else {
                    // Si asistió a alguna partida, rompe la cadena de no presentaciones consecutivas
                    // Por lo tanto, se detiene el conteo
                    break;
                }
            }
            
            // Si tiene 2 o más no presentaciones consecutivas, no puede inscribirse en línea
            if ($no_presentaciones_consecutivas >= 2) {
                return [
                    'puede_inscribirse' => false,
                    'razon' => 'Has tenido 2 o más no presentaciones consecutivas en eventos anteriores. Debes inscribirte presencialmente.',
                    'no_presentaciones_consecutivas' => $no_presentaciones_consecutivas,
                    'torneos_problema' => $torneos_problema
                ];
            }
            
            return [
                'puede_inscribirse' => true,
                'razon' => null,
                'no_presentaciones_consecutivas' => $no_presentaciones_consecutivas
            ];
        } catch (Exception $e) {
            error_log("Error verificando si usuario puede inscribirse en línea: " . $e->getMessage());
            // En caso de error, permitir la inscripción pero registrar el error
            return [
                'puede_inscribirse' => true,
                'razon' => null,
                'error' => 'Error al verificar historial. Se permite la inscripción.'
            ];
        }
    }
    
    /**
     * Verifica si un usuario debe pagar para un torneo antes de inscribirse en línea
     * 
     * @param PDO $pdo Conexión a la base de datos
     * @param int $torneo_id ID del torneo
     * @param int $id_usuario ID del usuario
     * @return array ['debe_pagar' => bool, 'costo' => float, 'ya_pago' => bool, 'mensaje' => string|null]
     */
    public static function validarPagoAntesInscripcion(PDO $pdo, int $torneo_id, int $id_usuario): array {
        try {
            // Obtener información del torneo
            $stmt = $pdo->prepare("SELECT costo FROM tournaments WHERE id = ?");
            $stmt->execute([$torneo_id]);
            $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$torneo) {
                return [
                    'debe_pagar' => false,
                    'costo' => 0,
                    'ya_pago' => false,
                    'mensaje' => 'Torneo no encontrado'
                ];
            }
            
            $costo = (float)$torneo['costo'];
            $debe_pagar = $costo > 0;
            
            if (!$debe_pagar) {
                return [
                    'debe_pagar' => false,
                    'costo' => 0,
                    'ya_pago' => true,
                    'mensaje' => null
                ];
            }
            
            // Verificar si ya está inscrito y si ya pagó
            $stmt = $pdo->prepare("SELECT estatus FROM inscritos WHERE torneo_id = ? AND id_usuario = ?");
            $stmt->execute([$torneo_id, $id_usuario]);
            $inscripcion = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inscripcion) {
                $estatusVal = $inscripcion['estatus'];
                $ya_pago = ($estatusVal === self::ESTATUS_CONFIRMADO || $estatusVal === 'solvente');
                
                if ($ya_pago) {
                    return [
                        'debe_pagar' => true,
                        'costo' => $costo,
                        'ya_pago' => true,
                        'mensaje' => 'Ya has pagado tu inscripción'
                    ];
                } else {
                    return [
                        'debe_pagar' => true,
                        'costo' => $costo,
                        'ya_pago' => false,
                        'mensaje' => 'Debes pagar el costo de inscripción (' . number_format($costo, 2) . ') antes de poder inscribirte en línea'
                    ];
                }
            }
            
            // Si no está inscrito y debe pagar, debe pagar primero
            return [
                'debe_pagar' => true,
                'costo' => $costo,
                'ya_pago' => false,
                'mensaje' => 'Debes pagar el costo de inscripción (' . number_format($costo, 2) . ') antes de poder inscribirte en línea'
            ];
        } catch (Exception $e) {
            error_log("Error validando pago antes de inscripción: " . $e->getMessage());
            return [
                'debe_pagar' => false,
                'costo' => 0,
                'ya_pago' => false,
                'mensaje' => 'Error al validar pago'
            ];
        }
    }
}

