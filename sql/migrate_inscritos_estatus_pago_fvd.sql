-- FVD: estatus de inscritos como pago (0=pendiente, 1=pagado, 4=retirado)
-- Ejecutar una sola vez en la base del proyecto.

-- 1) Reservar cancelados legacy (antiguo modelo 1=cancelado) antes de reasignar pagados
UPDATE inscritos SET estatus = 91
WHERE CAST(estatus AS CHAR) = '1'
  AND id IN (
    SELECT id FROM (
      SELECT i.id FROM inscritos i
      LEFT JOIN reportes_pago_usuarios r ON r.torneo_id = i.torneo_id AND r.id_usuario = i.id_usuario AND r.estatus = 'confirmado'
      WHERE CAST(i.estatus AS CHAR) = '1' AND r.id IS NULL
    ) t
  );

-- 2) Confirmados legacy (2) → pagado (1)
UPDATE inscritos SET estatus = 1 WHERE CAST(estatus AS CHAR) IN ('2', 'confirmado', 'solvente');

-- 3) Cancelados legacy → pendiente
UPDATE inscritos SET estatus = 0 WHERE CAST(estatus AS CHAR) IN ('91', 'cancelado');
