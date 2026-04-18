-- Reversión manual: datos que quedaron en torneo 19 vuelven al torneo 18.
-- Ejecutar en MySQL/MariaDB tras una segmentación incorrecta. Revise ids antes de aplicar.
--
-- Orden: equipos e inscritos primero; partiresul y demás alineados al torneo destino.

START TRANSACTION;

UPDATE equipos SET id_torneo = 18 WHERE id_torneo = 19;
UPDATE inscritos SET torneo_id = 18 WHERE torneo_id = 19;
UPDATE partiresul SET id_torneo = 18 WHERE id_torneo = 19;

-- Si alguna tabla no existe en su BD, comente la línea que falle.
-- UPDATE mesas_asignacion SET tournament_id = 18 WHERE tournament_id = 19;
-- UPDATE historial_parejas SET torneo_id = 18 WHERE torneo_id = 19;

-- Torneo 19 vacío: puede eliminar el registro duplicado (descomente si corresponde):
 DELETE FROM tournaments WHERE id = 19 LIMIT 1;

COMMIT;
