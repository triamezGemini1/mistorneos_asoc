-- MySQL 8+ schema for mistorneos
CREATE DATABASE IF NOT EXISTS mistorneos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mistorneos;

-- Roles & users


DROP TABLE IF EXISTS `usuarios`;-- User Registration Requests
CREATE TABLE IF NOT EXISTS usuarios (
  id INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(62) NOT NULL,
  cedula VARCHAR(20) NOT NULL,
  nacionalidad CHAR(1) NOT NULL DEFAULT 'V' COMMENT 'V=Venezolano, E=Extranjero, J=Jurídico, P=Pasaporte',
  sexo ENUM('M','F','O') NOT NULL DEFAULT 'M',
  fechnac DATE NULL,
  email VARCHAR(100) NOT NULL,
  categ INT NOT NULL DEFAULT 0,
  photo_path VARCHAR(200) NULL,
  uuid VARCHAR(36) NULL UNIQUE,
  recovery_token VARCHAR(64) NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin_general','admin_torneo','admin_club','usuario','operador') NOT NULL DEFAULT 'usuario',
  club_id INT NULL DEFAULT 0,
  entidad INT NOT NULL DEFAULT 0 COMMENT 'Código de la tabla entidad (ubicación geográfica)',
  status TINYINT NOT NULL DEFAULT 0 COMMENT '0=activo, 1=inactivo',
  requested_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at TIMESTAMP NULL,
  approved_by INT NULL,
  rejection_reason TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_cedula (cedula),
  KEY idx_status (status),
  KEY idx_email (email),
  KEY idx_username (username),
  CONSTRAINT fk_approved_by FOREIGN KEY (approved_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




DROP TABLE IF EXISTS `inscritos`;
CREATE TABLE IF NOT EXISTS `inscritos` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT NOT NULL,
  `torneo_id` INT NOT NULL,
  `id_club` INT DEFAULT NULL COMMENT 'Club al que pertenece el inscrito',
  `posicion` int DEFAULT '0' COMMENT 'Posición en la clasificación del torneo',
  `ganados` int DEFAULT '0' COMMENT 'Partidas ganadas',
  `perdidos` int DEFAULT '0' COMMENT 'Partidas perdidas',
  `efectividad` int DEFAULT '0' COMMENT 'La efectividad es un valor diferencial int',
  `puntos` int DEFAULT '0' COMMENT 'Puntos acumulados en el torneo',
  `ptosrnk` int UNSIGNED DEFAULT '0' COMMENT 'Puntos de ranking: puntos por posición + (partidas ganadas × puntos por partida ganada)',
  `sancion` int DEFAULT '0' COMMENT 'Código de sanción',
  `chancletas` int DEFAULT '0' COMMENT 'Contador de chancletas',
  `zapatos` int DEFAULT '0' COMMENT 'Contador de zapatos',
  `tarjeta` int DEFAULT '0' COMMENT 'Número de tarjetas',
  `fecha_inscripcion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `inscrito_por` INT DEFAULT NULL COMMENT 'ID del usuario que hizo la inscripción',
  `notas` text COLLATE utf8mb4_unicode_ci COMMENT 'Observaciones',
  `estatus` enum('pendiente','confirmado','solvente','no_solvente','retirado') COLLATE utf8mb4_unicode_ci DEFAULT 'pendiente',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_inscripcion` (`id_usuario`,`torneo_id`) COMMENT 'Un usuario solo puede inscribirse una vez por torneo',
  KEY `inscrito_por` (`inscrito_por`),
  KEY `idx_usuario` (`id_usuario`),
  KEY `idx_torneo` (`torneo_id`),
  KEY `idx_club` (`id_club`),
  KEY `idx_estatus` (`estatus`),
  KEY `idx_puntos` (`puntos`),
  KEY `idx_posicion` (`posicion`),
  KEY `idx_ptosrnk` (`ptosrnk`),
  KEY `idx_inscritos_torneo_estatus` (`torneo_id`,`estatus`) COMMENT 'Conteos y listados por torneo y estatus (Mejora 2)',
  KEY `idx_inscritos_clasificacion` (`torneo_id`,`posicion`,`ganados`,`efectividad`,`puntos`) COMMENT 'ORDER BY clasificación en generación de rondas (Mejora 2)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Restricciones para tablas volcadas
--



-- Organizaciones (Federaciones, Asociaciones)
CREATE TABLE IF NOT EXISTS organizaciones (
  id INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(255) NOT NULL,
  direccion VARCHAR(255) NULL,
  responsable VARCHAR(100) NULL COMMENT 'Nombre del responsable/presidente',
  telefono VARCHAR(50) NULL,
  email VARCHAR(100) NULL,
  entidad INT NOT NULL DEFAULT 0 COMMENT 'Código de entidad geográfica (estado/región)',
  admin_user_id INT NOT NULL COMMENT 'Usuario admin_club que registró/gestiona esta organización',
  logo VARCHAR(255) NULL,
  estatus TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_user_id (admin_user_id),
  KEY idx_entidad (entidad),
  KEY idx_estatus (estatus),
  CONSTRAINT fk_organizaciones_admin FOREIGN KEY (admin_user_id) REFERENCES usuarios(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clubs
CREATE TABLE IF NOT EXISTS clubes (
  id INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(255) NOT NULL,
  direccion VARCHAR(255) NULL,
  delegado VARCHAR(50) NULL,
  delegado_user_id INT NULL DEFAULT NULL COMMENT 'ID usuario responsable (admin_club o usuario). NULL=usar delegado',
  telefono VARCHAR(50) NULL,
  email VARCHAR(255) NULL,
  indica INT NOT NULL DEFAULT 0,
  estatus TINYINT NOT NULL DEFAULT 1,
  admin_club_id INT NULL DEFAULT NULL COMMENT 'ID del usuario admin_club que gestiona este club',
  cod_org INT NULL COMMENT 'Código de organización (organizaciones.cod_org)',
  entidad INT NOT NULL DEFAULT 0 COMMENT 'Entidad de la organización (ámbito territorial). Obligatorio.',
  logo VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_club_id (admin_club_id),
  KEY idx_delegado_user_id (delegado_user_id),
  KEY idx_clubes_cod_org (cod_org),
  KEY idx_clubes_entidad (entidad),
  CONSTRAINT fk_clubes_admin_club FOREIGN KEY (admin_club_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_clubes_delegado_user FOREIGN KEY (delegado_user_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla clubes_asociados ELIMINADA: la relación se maneja por organizacion_id en clubes.
-- Ya no se crea esta tabla.

-- Tournaments
CREATE TABLE IF NOT EXISTS tournaments (
  id INT NOT NULL AUTO_INCREMENT,
  clase INT NOT NULL DEFAULT 0,
  modalidad INT NOT NULL DEFAULT 0,
  tiempo INT NOT NULL DEFAULT 35,
  puntos INT NOT NULL DEFAULT 200,
  rondas INT NOT NULL DEFAULT 9,
  estatus TINYINT NOT NULL DEFAULT 1,
  costo INT NOT NULL DEFAULT 0,
  ranking INT NOT NULL DEFAULT 0,
  pareclub INT NOT NULL DEFAULT 0,
  fechator DATE NULL,
  nombre VARCHAR(200) NOT NULL,
  invitacion VARCHAR(200) NULL,
  normas VARCHAR(200) NULL,
  afiche VARCHAR(200) NULL,
  club_responsable INT NULL COMMENT 'ID de la organización que organiza el torneo',
  cod_org INT NULL COMMENT 'Código de organización (organizaciones.cod_org); preferir club_responsable',
  owner_user_id INT NULL COMMENT 'Usuario que registró el torneo',
  entidad INT NOT NULL DEFAULT 0 COMMENT 'Entidad de la organización (ámbito territorial). Obligatorio.',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_club_responsable (club_responsable),
  KEY idx_tournaments_entidad (entidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





-- Payments
CREATE TABLE IF NOT EXISTS payments (
  id INT NOT NULL AUTO_INCREMENT,
  torneo_id INT NOT NULL,
  club_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(30) NOT NULL,
  reference VARCHAR(100) NULL,
  status ENUM('pendiente','confirmado','rechazado') NOT NULL DEFAULT 'pendiente',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_torneo (torneo_id),
  KEY idx_club (club_id),
  CONSTRAINT fk_pay_torneo FOREIGN KEY (torneo_id) REFERENCES tournaments(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pay_club FOREIGN KEY (club_id) REFERENCES clubes(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed admin user (password: admin123 - PLEASE CHANGE)

-- Usuario administrador: Trino Amezquita
INSERT INTO usuarios (
  nombre, 
  cedula, 
  sexo, 
  fechnac, 
  email, 
  username, 
  password_hash, 
  role, 
  status, 
  approved_at
) VALUES (
  'Trino Amezquita',
  '4978399',
  'M',
  '1956-06-06',
  'viajacontrino@gmail.com',
  'Trinoamez',
  SHA2('npi$2025', 256),
  'admin_general',
  'approved',
  NOW()
);

--
-- Filtros para la tabla `inscritos`
--
ALTER TABLE `inscritos`
  ADD CONSTRAINT `inscritos_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inscritos_ibfk_2` FOREIGN KEY (`torneo_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inscritos_ibfk_3` FOREIGN KEY (`id_club`) REFERENCES `clubes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inscritos_ibfk_4` FOREIGN KEY (`inscrito_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;
COMMIT;

