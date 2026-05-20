-- Organizaciones particulares (tipo_org = 1) vs asociaciones territoriales (tipo_org = 0)
--
-- Regla de negocio:
-- - Asociación: cod_org = código de entidad territorial; agrupa clubes de esa federación.
-- - Particular: entidad = referencia geográfica (misma tabla entidad), NO es miembro de la asociación;
--   cod_org debe ser único (típicamente = id) para no colisionar con el código territorial.
--
-- 1) Reparar particulares cuyo cod_org quedó igual a entidad (mezcla con asociación):
UPDATE organizaciones o
INNER JOIN (
    SELECT id
    FROM organizaciones
    WHERE COALESCE(tipo_org, 0) = 1
      AND admin_user_id IS NOT NULL
      AND admin_user_id > 0
      AND COALESCE(cod_org, 0) = COALESCE(entidad, 0)
      AND COALESCE(entidad, 0) > 0
) p ON p.id = o.id
SET o.cod_org = o.id
WHERE o.cod_org <> o.id;

-- 2) Verificación: particulares no deben compartir cod_org con asociación de la misma entidad
-- SELECT o.id, o.nombre, o.tipo_org, o.cod_org, o.entidad
-- FROM organizaciones o
-- WHERE COALESCE(o.tipo_org, 0) = 1
--   AND EXISTS (
--     SELECT 1 FROM organizaciones a
--     WHERE COALESCE(a.tipo_org, 0) = 0
--       AND a.entidad = o.entidad
--       AND COALESCE(a.cod_org, a.entidad) = COALESCE(o.cod_org, 0)
--   );
