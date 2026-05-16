-- Renombrar asociacion_id -> id_club para alinear con clubes.id (mismo criterio que inscritos.id_club).
-- Ejecutar si la tabla ya existe con la columna antigua.
--
-- Nota: tras CHANGE COLUMN, el índice `idx_mov_torneo_asociacion` (si existía) sigue aplicando a `id_club`
-- pero conserva el nombre antiguo. Renómbralo o recréalo según tu versión de MySQL/MariaDB.

ALTER TABLE `movimiento_torneo`
  CHANGE COLUMN `asociacion_id` `id_club` int DEFAULT NULL COMMENT 'FK lógica a clubes.id; club al que imputa el movimiento (contable / operativo)';

-- MySQL 8.0.13+ / MariaDB 10.5.2+: renombrar índice (descomentar si aplica)
-- ALTER TABLE `movimiento_torneo` RENAME INDEX `idx_mov_torneo_asociacion` TO `idx_mov_torneo_club`;

-- Si no tienes RENAME INDEX: eliminar el índice viejo y crear el nuevo (solo si el nombre sigue siendo idx_mov_torneo_asociacion)
-- ALTER TABLE `movimiento_torneo` DROP INDEX `idx_mov_torneo_asociacion`;
-- ALTER TABLE `movimiento_torneo` ADD KEY `idx_mov_torneo_club` (`id_club`);
