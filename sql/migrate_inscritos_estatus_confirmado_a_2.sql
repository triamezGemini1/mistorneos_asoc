-- Migración: separar cancelado (1) de confirmado (2) en inscritos.estatus
-- Ejecutar ANTES de desplegar el código que usa 0=pendiente, 1=cancelado, 2=confirmado, 4=retirado
--
-- Convierte inscripciones confirmadas legacy (valor 1 o texto confirmado) a 2.

UPDATE inscritos
SET estatus = 2
WHERE CAST(estatus AS CHAR) IN ('1', 'confirmado', 'solvente', 'no_solvente');
