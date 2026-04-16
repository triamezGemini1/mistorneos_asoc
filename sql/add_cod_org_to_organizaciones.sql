-- Agrega identificador de organización por entidad (cod_org)
-- Regla: para asociaciones, cod_org = id de entidad.

ALTER TABLE organizaciones
ADD COLUMN IF NOT EXISTS cod_org INT NULL AFTER id;

CREATE INDEX IF NOT EXISTS idx_organizaciones_cod_org
ON organizaciones (cod_org);

-- Backfill inicial para organizaciones sin cod_org
UPDATE organizaciones
SET cod_org = entidad
WHERE (cod_org IS NULL OR cod_org = 0)
  AND COALESCE(entidad, 0) > 0;
