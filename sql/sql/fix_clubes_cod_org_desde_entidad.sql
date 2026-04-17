-- Rellena clubes.cod_org cuando quedó NULL pero existe entidad (misma semántica que normalizar_modelo_organizacion_canonico.sql).
-- Ejecutar en mantenimiento tras revisar un backup.

UPDATE clubes
SET cod_org = NULLIF(entidad, 0)
WHERE (cod_org IS NULL OR cod_org = 0)
  AND entidad IS NOT NULL
  AND entidad > 0
  AND estatus = 1;
