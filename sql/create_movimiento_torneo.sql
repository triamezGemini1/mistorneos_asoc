-- Tabla de movimientos por torneo (afiliación, anualidad, carnet, traspaso, inscripción, etc.)
-- id_club referencia clubes.id (mismo criterio que inscritos.id_club / equipos.id_club) para trazabilidad contable por club.
-- Ejecutar una sola vez en entornos nuevos.

CREATE TABLE IF NOT EXISTS `movimiento_torneo` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_usuario` int NOT NULL,
  `cedula` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `numfvd` int NOT NULL DEFAULT '0',
  `sexo` int NOT NULL DEFAULT '0',
  `id_club` int DEFAULT NULL COMMENT 'FK lógica a clubes.id; club al que imputa el movimiento (contable / operativo)',
  `estatus` int NOT NULL DEFAULT '0',
  `afiliacion` int NOT NULL DEFAULT '0',
  `anualidad` int NOT NULL DEFAULT '0',
  `carnet` int NOT NULL DEFAULT '0',
  `traspaso` int NOT NULL DEFAULT '0',
  `inscripcion` int NOT NULL DEFAULT '0',
  `torneo_id` int NOT NULL DEFAULT '0',
  `posrnk` int NOT NULL DEFAULT '0',
  `grupo_nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nombre de pareja o de equipo (modalidades 2 y 3)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mov_torneo_usuario` (`id_usuario`),
  KEY `idx_mov_torneo_torneo` (`torneo_id`),
  KEY `idx_mov_torneo_club` (`id_club`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opcional: integridad referencial (descomentar si clubes.id existe y no hay valores huérfanos)
-- ALTER TABLE `movimiento_torneo`
--   ADD CONSTRAINT `fk_movimiento_torneo_club` FOREIGN KEY (`id_club`) REFERENCES `clubes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
