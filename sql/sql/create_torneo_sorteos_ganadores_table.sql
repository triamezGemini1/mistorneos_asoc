-- Sorteos de premios entre inscritos del torneo (gestión / evento).
-- Ejecutar una vez en producción si la tabla no existe.

CREATE TABLE IF NOT EXISTS torneo_sorteos_ganadores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  torneo_id INT NOT NULL,
  id_usuario INT NOT NULL,
  premio_label VARCHAR(255) NOT NULL DEFAULT '',
  batch_id VARCHAR(64) NOT NULL,
  orden TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  KEY idx_torneo (torneo_id),
  KEY idx_batch (batch_id),
  KEY idx_torneo_usuario (torneo_id, id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
