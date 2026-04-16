-- Normaliza la relación clubes -> organizaciones para usar cod_org como referencia principal.
-- Regla objetivo: clubes.organizacion_id = organizaciones.cod_org (cuando exista cod_org válido).

UPDATE clubes c
INNER JOIN organizaciones o ON c.organizacion_id = o.id
SET c.organizacion_id = o.cod_org
WHERE COALESCE(o.cod_org, 0) > 0
  AND c.organizacion_id <> o.cod_org;

-- Verificación rápida sugerida:
-- SELECT c.id, c.nombre, c.organizacion_id, o.id AS org_id, o.cod_org
-- FROM clubes c
-- LEFT JOIN organizaciones o ON (c.organizacion_id = o.id OR c.organizacion_id = o.cod_org)
-- WHERE o.id IS NULL;
