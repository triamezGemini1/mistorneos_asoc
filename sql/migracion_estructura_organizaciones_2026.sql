-- ============================================================
-- Estructura: asociaciones (tipo_org=0) vs particulares (tipo_org=1)
-- Ejecutar en phpMyAdmin con la BD correcta seleccionada:
--   Beta: laestaci1_mistorneos_beta
--   Prod: laestaci1_mistorneos
-- Después ejecutar: fix_cod_org_organizaciones_particulares.sql
-- ============================================================

-- Columna discriminadora (idempotente en MySQL 8+ / MariaDB 10.3+)
ALTER TABLE `organizaciones`
  ADD COLUMN IF NOT EXISTS `tipo_org` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '0=asociación territorial, 1=organización particular (afiliado independiente)'
  AFTER `entidad`;

-- Índice para listados y reportes por tipo
CREATE INDEX IF NOT EXISTS `idx_organizaciones_tipo_org` ON `organizaciones` (`tipo_org`, `estatus`);

-- Marcar explícitamente asociaciones existentes (por si la columna era nueva)
UPDATE `organizaciones`
SET `tipo_org` = 0
WHERE COALESCE(`tipo_org`, 0) = 0
  AND (`admin_user_id` IS NOT NULL OR COALESCE(`entidad`, 0) > 0);

-- Verificación (debe devolver al menos una fila con Field = tipo_org):
-- SHOW COLUMNS FROM organizaciones LIKE 'tipo_org';
