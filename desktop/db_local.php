<?php
/**
 * Conexión a base de datos SQLite local (cliente desktop / Offline-First).
 * Este archivo no debe producir salida (no echo/print, sin espacio antes de <?php) para evitar "headers already sent".
 * Ruta única: DESKTOP_DB_PATH (mistorneos/desktop/data/mistorneos_local.db).
 * CLI e interfaz web (public/desktop) comparten este mismo archivo y esquema.
 */
declare(strict_types=1);

if (!defined('DESKTOP_DB_LOADED')) {
    define('DESKTOP_DB_LOADED', true);
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'config.php';

class DB_Local
{
    private static ?PDO $pdo = null;

    /** Ruta única y absoluta (definida en core/config.php). */
    private static function getDbPath(): string
    {
        $path = defined('DESKTOP_DB_PATH') ? DESKTOP_DB_PATH : (__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mistorneos_local.db');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $path;
    }

    /**
     * Obtiene la conexión PDO a SQLite
     */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = self::getDbPath();
            $dsn = 'sqlite:' . $path;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$pdo = new PDO($dsn, null, null, $options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::ensureLocalSchema(self::$pdo);
        }
        return self::$pdo;
    }

    /**
     * Crea el archivo mistorneos_local.db si no existe y replica la estructura
     * de las tablas MySQL (usuarios/jugadores, tournaments, inscritos, payments)
     * incluyendo uuid, last_updated y sync_status.
     */
    public static function ensureLocalSchema(?PDO $pdo = null): void
    {
        $pdo = $pdo ?? self::pdo();

        // usuarios (jugadores)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre TEXT,
                cedula TEXT,
                nacionalidad TEXT DEFAULT 'V',
                sexo TEXT DEFAULT 'M',
                fechnac TEXT,
                email TEXT,
                categ INTEGER DEFAULT 0,
                photo_path TEXT,
                uuid TEXT UNIQUE,
                recovery_token TEXT,
                username TEXT,
                password_hash TEXT,
                role TEXT DEFAULT 'usuario',
                club_id INTEGER DEFAULT 0,
                entidad INTEGER DEFAULT 0,
                status INTEGER DEFAULT 0,
                requested_at TEXT,
                approved_at TEXT,
                approved_by INTEGER,
                rejection_reason TEXT,
                last_updated TEXT,
                sync_status INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios_uuid ON usuarios(uuid)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios_last_updated ON usuarios(last_updated)");
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_active INTEGER DEFAULT 1"); } catch (Throwable $e) { }
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN creado_por INTEGER NULL"); } catch (Throwable $e) { }
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN fecha_creacion TEXT NULL"); } catch (Throwable $e) { }

        // tournaments (torneos)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tournaments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                clase INTEGER DEFAULT 0,
                modalidad INTEGER DEFAULT 0,
                tiempo INTEGER DEFAULT 35,
                puntos INTEGER DEFAULT 200,
                rondas INTEGER DEFAULT 9,
                estatus INTEGER DEFAULT 1,
                costo INTEGER DEFAULT 0,
                ranking INTEGER DEFAULT 0,
                pareclub INTEGER DEFAULT 0,
                fechator TEXT,
                nombre TEXT,
                invitacion TEXT,
                normas TEXT,
                afiche TEXT,
                club_responsable INTEGER,
                cod_org INTEGER,
                owner_user_id INTEGER,
                entidad INTEGER DEFAULT 0,
                created_at TEXT,
                updated_at TEXT,
                uuid TEXT UNIQUE,
                last_updated TEXT,
                sync_status INTEGER DEFAULT 0
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tournaments_uuid ON tournaments(uuid)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tournaments_last_updated ON tournaments(last_updated)");
        try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN modalidad INTEGER DEFAULT 0"); } catch (Throwable $e) { }
        try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN lugar TEXT"); } catch (Throwable $e) { }
        try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN es_evento_masivo INTEGER DEFAULT 0"); } catch (Throwable $e) { }
        try { $pdo->exec("ALTER TABLE tournaments RENAME COLUMN organizacion_id TO cod_org"); } catch (Throwable $e) { }

        // inscritos (con codigo_equipo para torneos por equipos)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inscritos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                id_usuario INTEGER NOT NULL,
                torneo_id INTEGER NOT NULL,
                id_club INTEGER,
                codigo_equipo TEXT,
                entidad_id INTEGER DEFAULT 0,
                posicion INTEGER DEFAULT 0,
                ganados INTEGER DEFAULT 0,
                perdidos INTEGER DEFAULT 0,
                efectividad INTEGER DEFAULT 0,
                puntos INTEGER DEFAULT 0,
                ptosrnk INTEGER DEFAULT 0,
                sancion INTEGER DEFAULT 0,
                chancletas INTEGER DEFAULT 0,
                zapatos INTEGER DEFAULT 0,
                tarjeta INTEGER DEFAULT 0,
                fecha_inscripcion TEXT,
                inscrito_por INTEGER,
                notas TEXT,
                estatus TEXT DEFAULT 'pendiente',
                uuid TEXT UNIQUE,
                last_updated TEXT,
                sync_status INTEGER DEFAULT 0
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inscritos_uuid ON inscritos(uuid)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inscritos_last_updated ON inscritos(last_updated)");
        try { $pdo->exec("ALTER TABLE inscritos ADD COLUMN codigo_equipo TEXT"); } catch (Throwable $e) { }
        try { $pdo->exec("ALTER TABLE inscritos ADD COLUMN entidad_id INTEGER DEFAULT 0"); } catch (Throwable $e) { }
        try { $pdo->exec("ALTER TABLE inscritos ADD COLUMN numero INTEGER DEFAULT 0"); } catch (Throwable $e) { }
        try { $pdo->exec("ALTER TABLE inscritos ADD COLUMN clasiequi INTEGER DEFAULT 0"); } catch (Throwable $e) { }
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inscritos_torneo_equipo ON inscritos(torneo_id, codigo_equipo)");

        // partiresul (resultados por mesa/ronda; necesario para logica_torneo y MesaAsignacionService)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS partiresul (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                id_torneo INTEGER NOT NULL,
                partida INTEGER NOT NULL,
                mesa INTEGER NOT NULL,
                secuencia INTEGER NOT NULL,
                id_usuario INTEGER NOT NULL,
                resultado1 INTEGER DEFAULT 0,
                resultado2 INTEGER DEFAULT 0,
                efectividad INTEGER DEFAULT 0,
                ff INTEGER DEFAULT 0,
                tarjeta INTEGER DEFAULT 0,
                sancion INTEGER DEFAULT 0,
                chancleta INTEGER DEFAULT 0,
                zapato INTEGER DEFAULT 0,
                fecha_partida TEXT,
                registrado_por INTEGER NOT NULL DEFAULT 1,
                observaciones TEXT,
                registrado INTEGER DEFAULT 0,
                estatus INTEGER DEFAULT 1
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_partiresul_torneo ON partiresul(id_torneo)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_partiresul_torneo_partida_mesa ON partiresul(id_torneo, partida, mesa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_partiresul_registrado ON partiresul(id_torneo, registrado)");
        try { $pdo->exec("ALTER TABLE partiresul ADD COLUMN entidad_id INTEGER DEFAULT 0"); } catch (Throwable $e) { }

        // historial_parejas (parejas que ya jugaron juntas; solo datos del evento local)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS historial_parejas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                torneo_id INTEGER NOT NULL,
                ronda_id INTEGER NOT NULL,
                jugador_1_id INTEGER NOT NULL,
                jugador_2_id INTEGER NOT NULL,
                llave TEXT,
                entidad_id INTEGER DEFAULT 0
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historial_parejas_torneo ON historial_parejas(torneo_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historial_parejas_entidad ON historial_parejas(entidad_id)");

        // equipos (torneos modalidad equipos)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS equipos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                id_torneo INTEGER NOT NULL,
                id_club INTEGER NOT NULL DEFAULT 0,
                nombre_equipo TEXT NOT NULL,
                codigo_equipo TEXT NOT NULL,
                consecutivo_club INTEGER NOT NULL DEFAULT 1,
                estatus INTEGER NOT NULL DEFAULT 0,
                ganados INTEGER NOT NULL DEFAULT 0,
                perdidos INTEGER NOT NULL DEFAULT 0,
                efectividad INTEGER NOT NULL DEFAULT 0,
                puntos INTEGER NOT NULL DEFAULT 0,
                gff INTEGER NOT NULL DEFAULT 0,
                posicion INTEGER NOT NULL DEFAULT 0,
                sancion INTEGER NOT NULL DEFAULT 0,
                fecha_actualizacion TEXT DEFAULT (datetime('now'))
            )
        ");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_equipos_torneo_codigo ON equipos(id_torneo, codigo_equipo)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_equipos_torneo ON equipos(id_torneo)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_equipos_estatus ON equipos(estatus)");

        // payments
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                torneo_id INTEGER NOT NULL,
                club_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                method TEXT,
                reference TEXT,
                status TEXT DEFAULT 'pendiente',
                created_at TEXT,
                updated_at TEXT,
                uuid TEXT UNIQUE,
                last_updated TEXT,
                sync_status INTEGER DEFAULT 0
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payments_uuid ON payments(uuid)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payments_last_updated ON payments(last_updated)");

        // Maestros: entidad, organizaciones, clubes
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS entidad (
                codigo INTEGER PRIMARY KEY,
                nombre TEXT NOT NULL,
                sync_status INTEGER DEFAULT 0
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS organizaciones (
                id INTEGER PRIMARY KEY,
                nombre TEXT NOT NULL,
                entidad INTEGER DEFAULT 0,
                estatus INTEGER DEFAULT 1,
                sync_status INTEGER DEFAULT 0
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clubes (
                id INTEGER PRIMARY KEY,
                nombre TEXT NOT NULL,
                cod_org INTEGER,
                entidad INTEGER DEFAULT 0,
                estatus INTEGER DEFAULT 1,
                sync_status INTEGER DEFAULT 0
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clubes_cod_org ON clubes(cod_org)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clubes_entidad ON clubes(entidad)");
        try { $pdo->exec("ALTER TABLE clubes RENAME COLUMN organizacion_id TO cod_org"); } catch (Throwable $e) { }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS auditoria (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                usuario_id INTEGER NOT NULL,
                accion TEXT NOT NULL,
                detalle TEXT,
                entidad_tipo TEXT,
                entidad_id INTEGER,
                organizacion_id INTEGER,
                fecha TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                sync_status INTEGER NOT NULL DEFAULT 0
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auditoria_fecha ON auditoria(fecha)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auditoria_usuario ON auditoria(usuario_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auditoria_organizacion ON auditoria(organizacion_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auditoria_sync ON auditoria(sync_status)");
    }

    /**
     * Cierra la conexión (útil para scripts que terminan o para reabrir con otra ruta)
     */
    public static function close(): void
    {
        self::$pdo = null;
    }

    /**
     * Comprueba si la base local está disponible
     */
    public static function isAvailable(): bool
    {
        try {
            self::pdo();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
