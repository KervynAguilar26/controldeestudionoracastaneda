-- /sistema_nora/config/migrations/004_certificados_configuracion.sql
-- Configuración de plantillas, folios, libro/línea y trazabilidad de certificados.
-- Ejecutar una sola vez sobre sistema_nora_v2.

BEGIN;

-- 1) Configuración única del módulo de certificados
CREATE TABLE IF NOT EXISTS public.certificado_configuracion (
    id_config          integer PRIMARY KEY DEFAULT 1 CHECK (id_config = 1),
    plantilla_frente   varchar(255) NOT NULL DEFAULT 'assets/img/plantilla_frente.jpg',
    plantilla_reverso  varchar(255) NOT NULL DEFAULT 'assets/img/plantilla_reverso.jpg',
    libro_numero       varchar(100) NOT NULL DEFAULT 'Libro 1',
    folio_inicial      integer NOT NULL DEFAULT 1,
    linea_inicial      integer NOT NULL DEFAULT 1,
    folio_actual       integer NOT NULL DEFAULT 1,
    linea_actual       integer NOT NULL DEFAULT 1,
    id_autoridad       integer NULL REFERENCES public.configuracion_autoridades(id_autoridad) ON DELETE SET NULL,
    fecha_actualizacion timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO public.certificado_configuracion (id_config)
VALUES (1)
ON CONFLICT (id_config) DO NOTHING;

-- 2) Datos nuevos del certificado emitido
ALTER TABLE public.certificados_emitidos
    ADD COLUMN IF NOT EXISTS libro varchar(100) NOT NULL DEFAULT 'Libro 1',
    ADD COLUMN IF NOT EXISTS linea_libro integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS codigo_validacion varchar(40);

UPDATE public.certificados_emitidos
   SET codigo_validacion = 'VAL-' || upper(substr(md5(random()::text || id_certificado::text), 1, 12))
 WHERE codigo_validacion IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_certificados_codigo_validacion
    ON public.certificados_emitidos (codigo_validacion);

-- 3) Trazabilidad de envíos por correo
CREATE TABLE IF NOT EXISTS public.certificado_envios (
    id_envio       bigserial PRIMARY KEY,
    id_certificado integer NOT NULL REFERENCES public.certificados_emitidos(id_certificado) ON DELETE CASCADE,
    correo         varchar(255) NOT NULL,
    estatus        varchar(20) NOT NULL DEFAULT 'Pendiente',
    mensaje        text,
    fecha_envio    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_certificado_envios_cert
    ON public.certificado_envios (id_certificado);

COMMIT;
