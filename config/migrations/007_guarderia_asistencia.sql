-- /sistema_nora/config/migrations/007_guarderia_asistencia.sql
-- Asistencia del Centro de Cuidados Infantiles: un marcaje por niño y día.
-- Evita duplicados y hace más rápido el listado diario.

BEGIN;

-- Conserva el primer marcaje del día y elimina duplicados históricos.
DELETE FROM public.guarderia_asistencia a
USING public.guarderia_asistencia b
WHERE a.id_asistencia > b.id_asistencia
  AND a.id_nino = b.id_nino
  AND a.fecha_asistencia = b.fecha_asistencia;

CREATE UNIQUE INDEX IF NOT EXISTS uq_guarderia_asis_nino_fecha
    ON public.guarderia_asistencia (id_nino, fecha_asistencia);

COMMIT;
