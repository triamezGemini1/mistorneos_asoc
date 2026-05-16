-- Crear base de datos para el clon FVD (ejecutar en phpMyAdmin o mysql CLI)
CREATE DATABASE IF NOT EXISTS mistorneos_fvd
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Si ya tienes datos en "mistorneos" y quieres copiarlos (descomenta y ejecuta fuera de este archivo):
-- mysqldump -u root mistorneos | mysql -u root mistorneos_fvd
