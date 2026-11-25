-- /sistema_nora/config/migrations/003_pernocta_dormitorios.sql
-- Flujo completo de eventos con pernocta:
--   * Responsable ÚNICO por cuarto con historial de cambios y motivo.
--   * Mudanzas entre dormitorios.
--   * Lista de espera cuando no hay cama disponible.
--   * Prórrogas (reutiliza public.dormitorio_prorrogas).
--   * Fecha final del evento con pernocta la carga el usuario al finalizar.
-- Fuente de verdad de las camas: public.dormitorios (id_dormitorio, capacidad_camas).

BEGIN;

-- ---------------------------------------------------------------------------
-- 1) Cohortes con pernocta: fecha final editable e inscripción habilitada
--    (solo se usa cuando la malla tiene requiere_pernocta = true).
-- ---------------------------------------------------------------------------
ALTER TABLE public.cohortes
    ADD COLUMN IF NOT EXISTS inscripcion_pernocta_abierta boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN public.cohortes.inscripcion_pernocta_abierta IS
    'Habilita el pre-registro por cédula de estudiantes para un evento con pernocta.';

-- ---------------------------------------------------------------------------
-- 2) Responsable del cuarto: exactamente UNO activo por dormitorio.
--    El historial conserva todos los responsables (incluye el que se retira).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.dormitorio_responsables (
    id_responsable_registro bigserial PRIMARY KEY,
    id_dormitorio           integer NOT NULL REFERENCES public.dormitorios(id_dormitorio) ON DELETE CASCADE,
    id_usuario              integer NOT NULL REFERENCES public.usuarios(id_usuario) ON DELETE RESTRICT,
    fecha_desde             timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_hasta             timestamp,
    motivo_cambio           text,
    id_usuario_registro     integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL
);

COMMENT ON TABLE public.dormitorio_responsables IS
    'Historial de responsables de cuarto. Solo uno activo (fecha_hasta IS NULL) por dormitorio.';

-- Garantiza un único responsable activo por dormitorio (índice parcial).
CREATE UNIQUE INDEX IF NOT EXISTS uq_dormitorio_responsable_activo
    ON public.dormitorio_responsables(id_dormitorio) WHERE (fecha_hasta IS NULL);

CREATE INDEX IF NOT EXISTS idx_dormitorio_responsables_usuario
    ON public.dormitorio_responsables(id_usuario);

-- ---------------------------------------------------------------------------
-- 3) Mudanzas entre dormitorios (trazabilidad de cada cambio de cuarto).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.dormitorio_mudanzas (
    id_mudanza          bigserial PRIMARY KEY,
    id_pernocta         integer NOT NULL REFERENCES public.dormitorio_pernoctas(id_pernocta) ON DELETE CASCADE,
    id_dormitorio_origen integer NOT NULL REFERENCES public.dormitorios(id_dormitorio),
    id_dormitorio_destino integer NOT NULL REFERENCES public.dormitorios(id_dormitorio),
    fecha_mudanza       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    motivo              text NOT NULL,
    id_usuario_registro integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL,
    CONSTRAINT dormitorio_mudanzas_distintos CHECK (id_dormitorio_origen <> id_dormitorio_destino)
);

COMMENT ON TABLE public.dormitorio_mudanzas IS
    'Registro de mudanzas de un huésped de un dormitorio a otro.';

-- ---------------------------------------------------------------------------
-- 4) Lista de espera de camas por evento (cohorte).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.dormitorio_lista_espera (
    id_lista_espera     bigserial PRIMARY KEY,
    id_cohorte          integer NOT NULL REFERENCES public.cohortes(id_cohorte) ON DELETE CASCADE,
    id_usuario          integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL,
    cedula              text NOT NULL,
    nombre_completo     text NOT NULL,
    telefono            text,
    fecha_solicitud     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estatus             text NOT NULL DEFAULT 'En Espera'
                            CHECK (estatus IN ('En Espera', 'Asignada', 'Cancelada')),
    id_pernocta_asignada integer REFERENCES public.dormitorio_pernoctas(id_pernocta) ON DELETE SET NULL,
    id_usuario_registro integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL,
    observaciones       text,
    CONSTRAINT dormitorio_lista_espera_unicos
        UNIQUE (id_cohorte, cedula)
);

COMMENT ON TABLE public.dormitorio_lista_espera IS
    'Personas esperando cama en un evento con pernocta cuando no hay disponibilidad.';

-- ---------------------------------------------------------------------------
-- 5) Prórrogas: vincular al usuario que autoriza y evitar duplicados abiertos.
--    La tabla public.dormitorio_prorrogas ya existía; solo se comenta/indiza.
-- ---------------------------------------------------------------------------
COMMENT ON TABLE public.dormitorio_prorrogas IS
    'Prórrogas de salida de pernocta (transporte no llegó, accidente, dificultad, etc.).';

CREATE INDEX IF NOT EXISTS idx_dormitorio_prorrogas_pernocta
    ON public.dormitorio_prorrogas(id_pernocta);

-- ---------------------------------------------------------------------------
-- 6) Habilitar responsables/pernoctas desde el alta de la cohorte y permitir
--    actualizar la fecha final al finalizar el evento (la carga el usuario).
--    (La lógica SQL se implementa en PHP; aquí solo se dejan columnas listas.)
-- ---------------------------------------------------------------------------
ALTER TABLE public.dormitorios
    ADD COLUMN IF NOT EXISTS fecha_ultima_responsable timestamp;

COMMIT;
