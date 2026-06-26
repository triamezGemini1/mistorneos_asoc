-- Homologación asociaciones ↔ entidad (referencia; ejecutar vía script PHP en producción).
-- Ver: scripts/homologar_asociaciones_entidades.php
--
-- Reglas:
-- 1) organizaciones.nombre = TRIM(entidad.nombre) para tipo_org = 0
-- 2) organizaciones.cod_org = organizaciones.entidad
-- 3) organizaciones.id = entidad (PK alineada; requiere script por FKs)
-- 4) clubes asociación: nombre desde entidad cuando cod_org = entidad y nombre coincide

-- Paso A: nombres y cod_org (seguro sin cambiar PK)
UPDATE organizaciones o
INNER JOIN entidad e ON e.id = o.entidad
SET o.nombre = TRIM(e.nombre),
    o.cod_org = o.entidad
WHERE COALESCE(o.tipo_org, 0) = 0
  AND COALESCE(o.entidad, 0) > 0;

UPDATE clubes c
INNER JOIN entidad e ON e.id = c.entidad
SET c.nombre = TRIM(e.nombre)
WHERE COALESCE(c.entidad, 0) > 0
  AND COALESCE(c.cod_org, 0) = c.entidad
  AND LOWER(TRIM(c.nombre)) COLLATE utf8mb4_unicode_ci
      = LOWER(TRIM(e.nombre)) COLLATE utf8mb4_unicode_ci;

-- Paso B: reorganizar PK organizaciones.id = entidad → usar script PHP (actualiza tournaments.club_responsable, etc.)

-- Paso C: alinear torneos y clubes (script PHP fase 3)
-- Torneos territoriales: club_responsable = cod_org = org canónica de tournaments.entidad
-- Torneos particulares: cod_org = club_responsable (org tipo_org=1)
-- Clubes: cod_org = entidad salvo afiliación a org particular
