-- /sistema_nora/config/migrations/001_horarios_docente.sql
-- Rango horario docente (20 a 40 h) y validación de disponibilidad / aulas / carga.
-- Ejecutar una sola vez sobre sistema_nora_v2.

BEGIN;

-- 1) Rango obligatorio de 20 a 40 horas semanales de trabajo
ALTER TABLE public.usuarios
    DROP CONSTRAINT IF EXISTS usuarios_horas_semanales_rango_chk;

UPDATE public.usuarios
   SET horas_semanales_min = 20
 WHERE horas_semanales_min IS NULL OR horas_semanales_min < 20;

UPDATE public.usuarios
   SET horas_semanales_max = 40
 WHERE horas_semanales_max IS NULL
    OR horas_semanales_max > 40
    OR horas_semanales_max < horas_semanales_min;

ALTER TABLE public.usuarios
    ADD CONSTRAINT usuarios_horas_semanales_rango_chk
    CHECK (
        horas_semanales_min >= 20
        AND horas_semanales_max <= 40
        AND horas_semanales_min <= horas_semanales_max
    );

-- 2) Utilidad SQL: 1 hora académica = 45 minutos
CREATE OR REPLACE FUNCTION public.docente_horas_bloque(p_ini time, p_fin time)
RETURNS integer AS $$
    SELECT GREATEST(1, ROUND(EXTRACT(EPOCH FROM (p_fin - p_ini)) / 60.0 / 45.0)::integer);
$$ LANGUAGE sql IMMUTABLE;

-- 3) Validación a nivel de base de datos (cualquier alta o edición de horario)
CREATE OR REPLACE FUNCTION public.validar_carga_docente()
RETURNS trigger AS $$
DECLARE
    v_usu          public.usuarios%ROWTYPE;
    v_choque       record;
    v_disp         jsonb;
    v_hora         text;
    v_horas_nuevas integer;
    v_horas_previas integer;
BEGIN
    IF NEW.id_profesor IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT * INTO v_usu FROM public.usuarios WHERE id_usuario = NEW.id_profesor;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Docente inexistente (id %).', NEW.id_profesor;
    END IF;

    -- a) Días y bloques disponibles (JSONB vacío = sin restricción)
    v_disp := v_usu.disponibilidad_horaria;
    IF v_disp IS NOT NULL
       AND jsonb_typeof(v_disp) = 'object'
       AND v_disp <> '{}'::jsonb THEN
        v_hora := to_char(NEW.hora_inicio, 'HH24:MI:SS');
        IF NOT (v_disp ? NEW.dia_semana) OR NOT (v_disp -> NEW.dia_semana ? v_hora) THEN
            RAISE EXCEPTION 'El docente % no tiene disponibilidad para el día % a las %.',
                trim(v_usu.nombre || ' ' || COALESCE(v_usu.apellido, '')),
                NEW.dia_semana,
                to_char(NEW.hora_inicio, 'HH:MI AM');
        END IF;
    END IF;

    -- b) Un docente no puede estar en dos aulas / secciones al mismo tiempo
    SELECT hs.salon_aula, co.codigo_cohorte, mm.nombre_materia,
           hs.dia_semana, hs.hora_inicio, hs.hora_fin
      INTO v_choque
      FROM public.horarios_secciones hs
      LEFT JOIN public.cohortes co ON co.id_cohorte = hs.id_cohorte
      LEFT JOIN public.malla_materias mm ON mm.id_materia = hs.id_materia
     WHERE hs.id_profesor = NEW.id_profesor
       AND hs.id_horario IS DISTINCT FROM NEW.id_horario
       AND hs.dia_semana = NEW.dia_semana
       AND hs.hora_inicio < NEW.hora_fin
       AND hs.hora_fin > NEW.hora_inicio
     LIMIT 1;

    IF FOUND THEN
        RAISE EXCEPTION 'Choque de horario: el docente % ya imparte % el día % de % a % en el aula % (sección %). No puede ocupar el aula % en el mismo bloque.',
            trim(v_usu.nombre || ' ' || COALESCE(v_usu.apellido, '')),
            COALESCE(v_choque.nombre_materia, 'una clase'),
            v_choque.dia_semana,
            to_char(v_choque.hora_inicio, 'HH:MI AM'),
            to_char(v_choque.hora_fin, 'HH:MI AM'),
            COALESCE(v_choque.salon_aula, 'N/D'),
            COALESCE(v_choque.codigo_cohorte, 'N/D'),
            COALESCE(NEW.salon_aula, 'otra');
    END IF;

    -- c) Límite de horas semanales del horario de trabajo (bloques asignados)
    v_horas_nuevas := public.docente_horas_bloque(NEW.hora_inicio, NEW.hora_fin);
    SELECT COALESCE(SUM(public.docente_horas_bloque(hora_inicio, hora_fin)), 0)
      INTO v_horas_previas
      FROM public.horarios_secciones
     WHERE id_profesor = NEW.id_profesor
       AND id_horario IS DISTINCT FROM NEW.id_horario;

    IF (v_horas_previas + v_horas_nuevas) > v_usu.horas_semanales_max THEN
        RAISE EXCEPTION 'Carga horaria excedida: el docente % tiene % h cargadas de un máximo de % h semanales. No se pueden asignar % h adicionales.',
            trim(v_usu.nombre || ' ' || COALESCE(v_usu.apellido, '')),
            v_horas_previas,
            v_usu.horas_semanales_max,
            v_horas_nuevas;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_carga_docente ON public.horarios_secciones;
CREATE TRIGGER trg_validar_carga_docente
    BEFORE INSERT OR UPDATE ON public.horarios_secciones
    FOR EACH ROW EXECUTE FUNCTION public.validar_carga_docente();

COMMIT;
