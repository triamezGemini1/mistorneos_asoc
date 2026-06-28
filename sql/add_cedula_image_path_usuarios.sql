-- Imagen escaneada de cédula de identidad (afiliados / usuarios)
ALTER TABLE `usuarios`
  ADD COLUMN IF NOT EXISTS `cedula_image_path` VARCHAR(200) NULL DEFAULT NULL COMMENT 'Ruta imagen escaneada de cédula' AFTER `photo_path`;
