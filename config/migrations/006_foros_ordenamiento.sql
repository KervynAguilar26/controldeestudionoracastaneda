-- /sistema_nora/config/migrations/006_foros_ordenamiento.sql
-- Ordenamiento del módulo de eventos extraordinarios:
--   * Foros, Conversatorios y Seminarios se crean en un solo módulo.
--   * La mesa se crea primero; el transcriptor se habilita después,
--     eligiendo a uno de los integrantes inscritos en esa mesa.
-- Ejecutar sobre sistema_nora_v2 (la migración 005 ya fue aplicada).

BEGIN;

-- Incluye Seminario como tipo válido de evento extraordinario.
ALTER TABLE public.foros_eventos
    DROP CONSTRAINT IF EXISTS foros_eventos_tipo_foro_check;

ALTER TABLE public.foros_eventos
    ADD CONSTRAINT foros_eventos_tipo_foro_check
    CHECK (tipo_foro IN ('Foro', 'Conversatorio', 'Seminario'));

-- La mesa puede crearse sin transcriptor y asignarlo después.
ALTER TABLE public.foro_mesas
    ALTER COLUMN id_transcriptor DROP NOT NULL;

COMMENT ON COLUMN public.foro_mesas.id_transcriptor IS
    'Integrante de la mesa habilitado como transcriptor; se asigna después de inscribir a los participantes.';

COMMIT;
