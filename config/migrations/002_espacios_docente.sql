-- /sistema_nora/config/migrations/002_espacios_docente.sql
-- Espacios escolares en los que el docente puede "hacer vida" (estar presente/impartir clase).

BEGIN;

ALTER TABLE public.usuarios
    ADD COLUMN IF NOT EXISTS espacios_permitidos jsonb;

-- Reemplaza la función de validación para incluir el chequeo de espacios.
CREATE OR REPLACE FUNCTION public.validar_carga_docente()
RETURNS trigger AS $$
DECLARE
    v_usu           public.usuarios%ROWTYPE;
    v_choque        record;
    v_disp          jsonb;
    v_hora          text;
    v_horas_nuevas  integer;
    v_horas_previas integer;
    v_espacios      jsonb;
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

    -- b) Espacios escolares autorizados para el docente
    v_espacios := v_usu.espacios_permitidos;
    IF NEW.salon_aula IS NOT NULL
       AND v_espacios IS NOT NULL
       AND jsonb_typeof(v_espacios) = 'array'
       AND jsonb_array_length(v_espacios) > 0 THEN
        IF NOT EXISTS (
            SELECT 1
              FROM jsonb_array_elements_text(v_espacios) AS e(valor)
              JOIN public.campus_espacios ce ON ce.id_espacio = e.valor::integer
             WHERE NEW.salon_aula ILIKE '%' || ce.nombre_espacio || '%'
        ) THEN
            RAISE EXCEPTION 'El docente % no tiene autorizado el espacio "%". Ajuste sus espacios en la jornada docente.',
                trim(v_usu.nombre || ' ' || COALESCE(v_usu.apellido, '')),
                NEW.salon_aula;
        END IF;
    END IF;

    -- c) Un docente no puede estar en dos aulas / secciones al mismo tiempo
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

    -- d) Límite de horas académicas semanales
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
