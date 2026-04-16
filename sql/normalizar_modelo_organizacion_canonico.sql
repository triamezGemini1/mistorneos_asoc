-- Modelo canónico solicitado:
-- 1) organización(asociación) nace de entidad, con cod_org = entidad
-- 2) clubes.organizacion_id debe guardar cod_org (no id interno)
-- 3) tournaments.club_responsable y tournaments.organizacion_id deben usar cod_org
-- 4) usuarios.organizacion_id se alinea a entidad

-- A) Garantizar cod_org en organizaciones basado en entidad
UPDATE organizaciones
SET cod_org = entidad
WHERE COALESCE(entidad, 0) > 0
  AND (cod_org IS NULL OR cod_org = 0 OR cod_org <> entidad);

-- B) Clubes: pasar de organizacion_id=id interno a organizacion_id=cod_org
UPDATE clubes c
INNER JOIN organizaciones o ON c.organizacion_id = o.id
SET c.organizacion_id = o.cod_org
WHERE COALESCE(o.cod_org, 0) > 0
  AND c.organizacion_id <> o.cod_org;

-- C) Torneos: club_responsable y organizacion_id al cod_org canónico
UPDATE tournaments t
INNER JOIN organizaciones o ON t.club_responsable = o.id
SET t.club_responsable = o.cod_org
WHERE COALESCE(o.cod_org, 0) > 0
  AND t.club_responsable <> o.cod_org;

UPDATE tournaments t
INNER JOIN organizaciones o ON t.organizacion_id = o.id
SET t.organizacion_id = o.cod_org
WHERE COALESCE(o.cod_org, 0) > 0
  AND t.organizacion_id <> o.cod_org;

-- D) Usuarios: organizacion_id representa entidad (según definición funcional)
UPDATE usuarios u
SET u.organizacion_id = u.entidad
WHERE COALESCE(u.entidad, 0) > 0
  AND COALESCE(u.organizacion_id, 0) <> COALESCE(u.entidad, 0);

-- E) Verificaciones recomendadas
-- 1) clubes con organizacion_id sin organización válida (por id/cod_org)
-- SELECT c.id, c.nombre, c.organizacion_id
-- FROM clubes c
-- LEFT JOIN organizaciones o ON (o.id = c.organizacion_id OR o.cod_org = c.organizacion_id)
-- WHERE o.id IS NULL;
--
-- 2) torneos con responsable sin organización válida
-- SELECT t.id, t.nombre, t.club_responsable
-- FROM tournaments t
-- LEFT JOIN organizaciones o ON (o.id = t.club_responsable OR o.cod_org = t.club_responsable)
-- WHERE o.id IS NULL;
