-- Homologación de nombres: referencia a organización por código (cod_org) en tablas hijas.
-- Ejecutar en mantenimiento (bloquea tablas brevemente).
--
-- IMPORTANTE — usuarios.entidad:
--   En este proyecto `usuarios.entidad` enlaza la tabla geográfica `entidad` (estado/región).
--   NO se renombra a cod_org para no romper JOINs territoriales.
--   Si existe `usuarios.organizacion_id`, se renombra a `cod_org` (afiliación a federación).
--
-- Antes: conviene ejecutar sql/normalizar_modelo_organizacion_canonico.sql para que los valores
-- en clubes/tournaments apunten a organizaciones.cod_org y no a organizaciones.id.

SET @db := DATABASE();

-- clubes.organizacion_id -> cod_org
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clubes' AND COLUMN_NAME = 'organizacion_id'
);
SET @sql := IF(@has > 0,
  'ALTER TABLE clubes CHANGE COLUMN organizacion_id cod_org INT NULL COMMENT ''Código de organización (organizaciones.cod_org)''',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tournaments.organizacion_id -> cod_org
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'organizacion_id'
);
SET @sql := IF(@has > 0,
  'ALTER TABLE tournaments CHANGE COLUMN organizacion_id cod_org INT NULL COMMENT ''Código de organización (organizaciones.cod_org); preferir club_responsable si aplica''',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- usuarios.organizacion_id -> cod_org (columna opcional en algunas instalaciones)
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'organizacion_id'
);
SET @sql := IF(@has > 0,
  'ALTER TABLE usuarios CHANGE COLUMN organizacion_id cod_org INT NULL DEFAULT NULL COMMENT ''Código de organización (organizaciones.cod_org)''',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
