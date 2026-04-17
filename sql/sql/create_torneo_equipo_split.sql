-- Equipos marcados para clasificación en bloque separado (grupo A); el resto del torneo = grupo B.
-- Solo admin general / herramienta de integración.
CREATE TABLE IF NOT EXISTS `torneo_equipo_split` (
  `torneo_id` INT UNSIGNED NOT NULL,
  `codigo_equipo` VARCHAR(20) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`torneo_id`, `codigo_equipo`),
  KEY `idx_torneo_equipo_split_torneo` (`torneo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grupo A: equipos separados para ranking propio; complemento = grupo B';
