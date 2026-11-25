-- /sistema_nora/config/migrations/005_foros_conversatorios.sql
-- Módulo independiente de Foros y Conversatorios:
--   * Malla/temas del evento, participantes y asistencia interna.
--   * Mesas de trabajo con transcriptor e informes de los participantes.
--   * Certificados (Oyente / Participante) con libro, folio, línea y QR.
--   * Vinculación de la pernocta al evento extraordinario y planta física de dormitorios.
-- Ejecutar una sola vez sobre sistema_nora_v2.

BEGIN;

-- 1) Evento (Foro, Conversatorio o Seminario) ------------------------------------
CREATE TABLE IF NOT EXISTS public.foros_eventos (
    id_foro            serial PRIMARY KEY,
    codigo_foro        varchar(50) NOT NULL UNIQUE,
    nombre             varchar(255) NOT NULL,
    tipo_foro          varchar(30) NOT NULL DEFAULT 'Foro'
                           CHECK (tipo_foro IN ('Foro', 'Conversatorio', 'Seminario')),
    descripcion        text,
    fecha_inicio       date,
    fecha_fin          date,
    lugar              varchar(255),
    horas_academicas   integer NOT NULL DEFAULT 0 CHECK (horas_academicas >= 0),
    id_malla           integer REFERENCES public.mallas_curriculares(id_malla) ON DELETE SET NULL,
    estatus            varchar(20) NOT NULL DEFAULT 'Planificado'
                           CHECK (estatus IN ('Planificado', 'Activo', 'Finalizado')),
    id_usuario_creador integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL,
    fecha_creacion     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE public.foros_eventos IS
    'Eventos extraordinarios (Foros/Conversatorios) con malla, participantes, mesas y certificados propios.';

-- 2) Temas cargados en la malla del evento ----------------------------------------
CREATE TABLE IF NOT EXISTS public.foro_temas (
    id_tema           serial PRIMARY KEY,
    id_foro           integer NOT NULL REFERENCES public.foros_eventos(id_foro) ON DELETE CASCADE,
    nombre_tema       varchar(255) NOT NULL,
    ponente           varchar(255),
    horas_academicas  integer NOT NULL DEFAULT 0 CHECK (horas_academicas >= 0),
    contenido         text,
    orden_tema        integer NOT NULL DEFAULT 1,
    fecha_registro    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_foro_temas_foro ON public.foro_temas(id_foro);

-- 3) Participantes (estudiantes) --------------------------------------------------
CREATE TABLE IF NOT EXISTS public.foro_participantes (
    id_participante  serial PRIMARY KEY,
    id_foro          integer NOT NULL REFERENCES public.foros_eventos(id_foro) ON DELETE CASCADE,
    id_estudiante    integer NOT NULL REFERENCES public.usuarios(id_usuario) ON DELETE RESTRICT,
    estatus          varchar(20) NOT NULL DEFAULT 'Inscrito'
                         CHECK (estatus IN ('Inscrito', 'Asistente', 'Participante')),
    fecha_registro   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario_registro integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL,
    observaciones    text,
    CONSTRAINT uq_foro_participante UNIQUE (id_foro, id_estudiante)
);

CREATE INDEX IF NOT EXISTS idx_foro_participantes_estudiante
    ON public.foro_participantes(id_estudiante);

-- 4) Asistencia interna: ponencia y mesa de trabajo -------------------------------
CREATE TABLE IF NOT EXISTS public.foro_asistencias (
    id_asistencia     serial PRIMARY KEY,
    id_participante   integer NOT NULL REFERENCES public.foro_participantes(id_participante) ON DELETE CASCADE,
    tipo_asistencia   varchar(30) NOT NULL
                          CHECK (tipo_asistencia IN ('Ponencia', 'Mesa de Trabajo')),
    fecha_registro    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    registrado_por    integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL,
    observaciones     text,
    CONSTRAINT uq_foro_asistencia UNIQUE (id_participante, tipo_asistencia)
);

-- 5) Mesas de trabajo (el transcriptor se asigna después de inscribir integrantes)
CREATE TABLE IF NOT EXISTS public.foro_mesas (
    id_mesa            serial PRIMARY KEY,
    id_foro            integer NOT NULL REFERENCES public.foros_eventos(id_foro) ON DELETE CASCADE,
    numero_mesa        integer NOT NULL CHECK (numero_mesa > 0),
    nombre_mesa        varchar(150),
    id_transcriptor    integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL,
    capacidad          integer NOT NULL DEFAULT 10 CHECK (capacidad > 0),
    estatus            varchar(15) NOT NULL DEFAULT 'Abierta'
                           CHECK (estatus IN ('Abierta', 'Cerrada')),
    informe_transcriptor text,
    fecha_informe_transcriptor timestamp,
    fecha_registro     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_foro_mesa_numero UNIQUE (id_foro, numero_mesa)
);

CREATE INDEX IF NOT EXISTS idx_foro_mesas_transcriptor ON public.foro_mesas(id_transcriptor);

CREATE TABLE IF NOT EXISTS public.foro_mesa_participantes (
    id_mesa_participante serial PRIMARY KEY,
    id_mesa              integer NOT NULL REFERENCES public.foro_mesas(id_mesa) ON DELETE CASCADE,
    id_participante      integer NOT NULL REFERENCES public.foro_participantes(id_participante) ON DELETE CASCADE,
    fecha_registro       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_foro_mesa_participante UNIQUE (id_mesa, id_participante)
);

-- 6) Informes escritos por los participantes en la mesa ---------------------------
CREATE TABLE IF NOT EXISTS public.foro_informes (
    id_informe          serial PRIMARY KEY,
    id_mesa             integer NOT NULL REFERENCES public.foro_mesas(id_mesa) ON DELETE CASCADE,
    id_participante     integer NOT NULL REFERENCES public.foro_participantes(id_participante) ON DELETE CASCADE,
    contenido           text NOT NULL,
    estatus             varchar(15) NOT NULL DEFAULT 'Borrador'
                            CHECK (estatus IN ('Borrador', 'Enviado')),
    fecha_envio         timestamp,
    fecha_actualizacion timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_foro_informe UNIQUE (id_mesa, id_participante)
);

CREATE INDEX IF NOT EXISTS idx_foro_informes_mesa ON public.foro_informes(id_mesa);

-- 7) Certificados del evento (rol Oyente / Participante) --------------------------
CREATE TABLE IF NOT EXISTS public.foro_certificados (
    id_certificado_foro   serial PRIMARY KEY,
    id_participante       integer NOT NULL UNIQUE
                              REFERENCES public.foro_participantes(id_participante) ON DELETE CASCADE,
    rol_certificado       varchar(20) NOT NULL CHECK (rol_certificado IN ('Oyente', 'Participante')),
    id_certificado_emitido integer REFERENCES public.certificados_emitidos(id_certificado) ON DELETE SET NULL,
    fecha_emision         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario_emisor     integer REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL
);

-- 8) Trazabilidad académica: los certificados de foro también se guardan en el
--    libro general de certificados del sistema.
ALTER TABLE public.certificados_emitidos
    ADD COLUMN IF NOT EXISTS origen varchar(20) NOT NULL DEFAULT 'Academico',
    ADD COLUMN IF NOT EXISTS id_foro integer REFERENCES public.foros_eventos(id_foro) ON DELETE SET NULL;

-- Los certificados de foro no tienen inscripción ni malla curricular: se
-- relajan esas columnas y se restringe el único por malla solo al origen
-- académico, para que un estudiante pueda tener varios certificados de foro.
ALTER TABLE public.certificados_emitidos
    ALTER COLUMN id_inscripcion DROP NOT NULL,
    ALTER COLUMN id_malla DROP NOT NULL;

ALTER TABLE public.certificados_emitidos
    DROP CONSTRAINT IF EXISTS certificado_unico_por_malla;

CREATE UNIQUE INDEX IF NOT EXISTS certificado_unico_academico
    ON public.certificados_emitidos(id_estudiante, id_malla, tipo)
    WHERE origen = 'Academico' AND id_malla IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_certificados_foro
    ON public.certificados_emitidos(id_foro);

-- 9) Pernocta vinculada al evento extraordinario y dormitorios por planta física --
ALTER TABLE public.dormitorio_pernoctas
    ADD COLUMN IF NOT EXISTS id_foro integer REFERENCES public.foros_eventos(id_foro) ON DELETE SET NULL;

ALTER TABLE public.dormitorios
    ADD COLUMN IF NOT EXISTS planta varchar(80);

-- La pernocta se habilita desde la configuración del Foro/Conversatorio.
ALTER TABLE public.foros_eventos
    ADD COLUMN IF NOT EXISTS pernocta_habilitada boolean NOT NULL DEFAULT false;

-- La lista de espera también puede pertenecer a un Foro (los eventos
-- académicos antiguos se conservan en id_cohorte).
ALTER TABLE public.dormitorio_lista_espera
    ADD COLUMN IF NOT EXISTS id_foro integer REFERENCES public.foros_eventos(id_foro) ON DELETE CASCADE;

ALTER TABLE public.dormitorio_lista_espera
    ALTER COLUMN id_cohorte DROP NOT NULL;

ALTER TABLE public.dormitorio_lista_espera
    DROP CONSTRAINT IF EXISTS dormitorio_lista_espera_unicos;

CREATE UNIQUE INDEX IF NOT EXISTS uq_lista_espera_foro
    ON public.dormitorio_lista_espera(id_foro, cedula)
    WHERE id_foro IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_lista_espera_cohorte
    ON public.dormitorio_lista_espera(id_cohorte, cedula)
    WHERE id_cohorte IS NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_lista_espera_evento') THEN
        ALTER TABLE public.dormitorio_lista_espera
            ADD CONSTRAINT chk_lista_espera_evento
            CHECK (id_foro IS NOT NULL OR id_cohorte IS NOT NULL);
    END IF;
END $$;

COMMENT ON COLUMN public.dormitorio_pernoctas.id_foro IS
    'Evento extraordinario (Foro/Conversatorio) al que asiste la persona pernoctada.';

COMMENT ON COLUMN public.dormitorios.planta IS
    'Planta física del edificio; en una misma planta pueden existir varios dormitorios.';

COMMIT;
