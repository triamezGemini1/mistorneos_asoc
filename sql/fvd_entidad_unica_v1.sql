-- Migración FVD: organización única (Federación Venezolana de Dominó)
-- Ejecutar una vez en la base de datos de mistorneos_fvd.

SET FOREIGN_KEY_CHECKS = 0;

-- Tablas SaaS / suscripción (eliminar si existen)
DROP TABLE IF EXISTS facturacion_organizaciones;
DROP TABLE IF EXISTS pagos_afiliados;
DROP TABLE IF EXISTS planes_suscripcion;

SET FOREIGN_KEY_CHECKS = 1;

-- Organización maestra FVD
DELETE FROM organizaciones;

-- Siglas (columna opcional)
SET @has_siglas := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'organizaciones'
      AND COLUMN_NAME = 'siglas'
);

SET @sql_siglas := IF(
    @has_siglas = 0,
    'ALTER TABLE organizaciones ADD COLUMN siglas VARCHAR(16) NULL AFTER nombre',
    'SELECT 1'
);
PREPARE stmt_siglas FROM @sql_siglas;
EXECUTE stmt_siglas;
DEALLOCATE PREPARE stmt_siglas;

SET @admin_id := (
    SELECT id FROM usuarios WHERE role = 'admin_general' AND status = 0 ORDER BY id ASC LIMIT 1
);
SET @admin_id := IFNULL(@admin_id, (SELECT id FROM usuarios ORDER BY id ASC LIMIT 1));

-- Entidad territorial de etiqueta para la federación (ámbito nacional; el alcance efectivo en reportes usa 0 vía app)
INSERT INTO entidad (id, nombre, estado)
VALUES (999, 'Nacional', 0)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO organizaciones (id, nombre, siglas, entidad, admin_user_id, estatus)
VALUES (
    1,
    'FEDERACION VENEZOLANA DE DOMINO',
    'FVD',
    999,
    @admin_id,
    1
)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    siglas = VALUES(siglas),
    entidad = VALUES(entidad),
    admin_user_id = VALUES(admin_user_id),
    estatus = VALUES(estatus);

-- Bases ya migradas que tenían entidad = 0
UPDATE organizaciones SET entidad = 999 WHERE id = 1 AND (entidad IS NULL OR entidad = 0);

-- Vincular clubes y torneos a la FVD
UPDATE clubes SET cod_org = 1 WHERE cod_org IS NULL OR cod_org <> 1;

UPDATE tournaments SET club_responsable = 1 WHERE club_responsable IS NULL OR club_responsable <> 1;
