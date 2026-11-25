-- /sistema_nora/config/migrations/008_certificados_folios_lineas.sql
-- Un folio del libro puede contener varias líneas (20 por defecto).
-- Antes: un folio por certificado y línea global 1..166.
-- Ahora: folio 1 líneas 1..20, folio 2 líneas 1..20, etc.
-- Ejecutar una sola vez sobre sistema_nora_v2.

BEGIN;

-- 1) Configurable desde el módulo de certificados.
ALTER TABLE public.certificado_configuracion
    ADD COLUMN IF NOT EXISTS lineas_por_folio integer NOT NULL DEFAULT 20;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'certificado_configuracion_lineas_por_folio_check'
    ) THEN
        ALTER TABLE public.certificado_configuracion
            ADD CONSTRAINT certificado_configuracion_lineas_por_folio_check
            CHECK (lineas_por_folio BETWEEN 1 AND 100);
    END IF;
END $$;

-- 2) El folio ya no es único por sí solo; la única combinación válida es folio + línea.
ALTER TABLE public.certificados_emitidos
    DROP CONSTRAINT IF EXISTS certificados_emitidos_folio_key;

-- 3) Renumerar los certificados existentes en folios de 20 líneas.
--    Se conservan el código de validación, la fecha, el origen y todos los ids.
WITH orden AS (
    SELECT ce.id_certificado,
           split_part(ce.folio, '-', 2) AS anio,
           row_number() OVER (
               ORDER BY substring(ce.folio from '([0-9]+)$')::int, ce.id_certificado
           ) AS n
      FROM public.certificados_emitidos ce
     WHERE ce.folio ~ '^CERT-[0-9]{4}-[0-9]+$'
), cfg AS (
    SELECT folio_inicial FROM public.certificado_configuracion WHERE id_config = 1
)
UPDATE public.certificados_emitidos ce
   SET folio = 'CERT-' || o.anio || '-' ||
               lpad(((o.n - 1) / 20 + c.folio_inicial)::text, 5, '0'),
       linea_libro = (((o.n - 1) % 20) + 1)::int
  FROM orden o
  CROSS JOIN cfg c
 WHERE ce.id_certificado = o.id_certificado;

-- 4) Garantizar integridad de la numeración a partir de ahora.
ALTER TABLE public.certificados_emitidos
    ADD CONSTRAINT certificados_emitidos_folio_linea_key UNIQUE (folio, linea_libro);

-- 5) Dejar los contadores apuntando a la próxima línea disponible.
WITH totals AS (
    SELECT COUNT(*) AS total FROM public.certificados_emitidos
), calc AS (
    SELECT (SELECT folio_inicial FROM public.certificado_configuracion WHERE id_config = 1)
           + total / 20 AS folio_siguiente,
           CASE WHEN total % 20 = 0 THEN 1 ELSE (total % 20) + 1 END AS linea_siguiente
      FROM totals
)
UPDATE public.certificado_configuracion c
   SET folio_actual = calc.folio_siguiente,
       linea_actual = calc.linea_siguiente,
       fecha_actualizacion = CURRENT_TIMESTAMP
  FROM calc
 WHERE c.id_config = 1;

COMMIT;
