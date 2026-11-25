--
-- PostgreSQL database dump
--

\restrict qUqme1GsaAN488FktuozSTHXQgRRb3sU4PUuITcI3nJklcwr8Oa00Bm9cXwWZJH

-- Dumped from database version 18.7
-- Dumped by pg_dump version 18.7

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

-- *not* creating schema, since initdb creates it


--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS '';


--
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- Name: docente_horas_bloque(time without time zone, time without time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.docente_horas_bloque(p_ini time without time zone, p_fin time without time zone) RETURNS integer
    LANGUAGE sql IMMUTABLE
    AS $$
    SELECT GREATEST(1, ROUND(EXTRACT(EPOCH FROM (p_fin - p_ini)) / 60.0 / 45.0)::integer);
$$;


--
-- Name: validar_carga_docente(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validar_carga_docente() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: alimentos_tabla_venezuela; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.alimentos_tabla_venezuela (
    codigo_alimento integer NOT NULL,
    nombre_alimento character varying(200) NOT NULL,
    grupo_alimento character varying(100),
    porcion_base_g numeric(10,2) DEFAULT 100.00,
    calorias_kcal numeric(10,2) DEFAULT 0.00,
    proteinas_g numeric(10,2) DEFAULT 0.00,
    grasas_totales_g numeric(10,2) DEFAULT 0.00,
    carbohidratos_g numeric(10,2) DEFAULT 0.00,
    fibra_dietetica_g numeric(10,2) DEFAULT 0.00,
    calcio_mg numeric(10,2) DEFAULT 0.00,
    fosforo_mg numeric(10,2) DEFAULT 0.00,
    hierro_mg numeric(10,2) DEFAULT 0.00,
    potasio_mg numeric(10,2) DEFAULT 0.00,
    sodio_mg numeric(10,2) DEFAULT 0.00,
    vitamina_c_mg numeric(10,2) DEFAULT 0.00
);


--
-- Name: asignaciones_profesor; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.asignaciones_profesor (
    id_asignacion integer NOT NULL,
    id_profesor integer NOT NULL,
    id_materia integer NOT NULL,
    justificacion_asignacion text NOT NULL,
    estatus_aceptacion character varying(20) DEFAULT 'Pendiente'::character varying,
    fecha_asignacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    fecha_respuesta timestamp without time zone,
    id_cohorte integer
);


--
-- Name: asignaciones_profesor_id_asignacion_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.asignaciones_profesor_id_asignacion_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: asignaciones_profesor_id_asignacion_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.asignaciones_profesor_id_asignacion_seq OWNED BY public.asignaciones_profesor.id_asignacion;


--
-- Name: asistencia_clases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.asistencia_clases (
    id_asistencia_clase integer NOT NULL,
    id_cohorte integer,
    id_estudiante integer,
    fecha_clase date DEFAULT CURRENT_DATE,
    hora_registro time without time zone DEFAULT CURRENT_TIME,
    estatus character varying(20) DEFAULT 'Presente'::character varying,
    CONSTRAINT asistencia_clases_estatus_check CHECK (((estatus)::text = ANY (ARRAY[('Presente'::character varying)::text, ('Inasistente'::character varying)::text, ('Justificado'::character varying)::text])))
);


--
-- Name: asistencia_clases_id_asistencia_clase_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.asistencia_clases_id_asistencia_clase_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: asistencia_clases_id_asistencia_clase_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.asistencia_clases_id_asistencia_clase_seq OWNED BY public.asistencia_clases.id_asistencia_clase;


--
-- Name: bitacora; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bitacora (
    id_log integer NOT NULL,
    id_usuario integer,
    modulo character varying(50) NOT NULL,
    accion text NOT NULL,
    ip_maquina character varying(45) NOT NULL,
    fecha_hora timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: bitacora_id_log_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.bitacora_id_log_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bitacora_id_log_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.bitacora_id_log_seq OWNED BY public.bitacora.id_log;


--
-- Name: campus_edificios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.campus_edificios (
    id_edificio integer NOT NULL,
    nombre_torre character varying(150) NOT NULL,
    ubicacion_sede character varying(150) DEFAULT 'Sede Principal'::character varying,
    total_pisos integer DEFAULT 1,
    descripcion text,
    estatus character varying(30) DEFAULT 'Disponible'::character varying,
    motivo_mantenimiento text,
    inspector_responsable character varying(150),
    trabajo_realizado text,
    usuario_apertura character varying(150)
);


--
-- Name: campus_edificios_id_edificio_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.campus_edificios_id_edificio_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: campus_edificios_id_edificio_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.campus_edificios_id_edificio_seq OWNED BY public.campus_edificios.id_edificio;


--
-- Name: campus_espacios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.campus_espacios (
    id_espacio integer NOT NULL,
    id_edificio integer,
    tipo_espacio character varying(50) NOT NULL,
    nombre_espacio character varying(150) NOT NULL,
    piso_numero integer DEFAULT 0,
    capacidad_estudiantes integer DEFAULT 30,
    cantidad_dormitorios integer DEFAULT 0,
    camas_totales integer DEFAULT 0,
    enlace_virtual character varying(255),
    estatus character varying(20) DEFAULT 'Disponible'::character varying,
    motivo_mantenimiento text,
    inspector_responsable character varying(150),
    trabajo_realizado text,
    usuario_apertura character varying(150)
);


--
-- Name: campus_espacios_id_espacio_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.campus_espacios_id_espacio_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: campus_espacios_id_espacio_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.campus_espacios_id_espacio_seq OWNED BY public.campus_espacios.id_espacio;


--
-- Name: carreras_misiones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.carreras_misiones (
    id_carrera integer NOT NULL,
    nombre_carrera character varying(150) NOT NULL,
    tipo_programa character varying(50) NOT NULL,
    duracion_meses integer DEFAULT 6
);


--
-- Name: carreras_misiones_id_carrera_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.carreras_misiones_id_carrera_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: carreras_misiones_id_carrera_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.carreras_misiones_id_carrera_seq OWNED BY public.carreras_misiones.id_carrera;


--
-- Name: certificado_configuracion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.certificado_configuracion (
    id_config integer DEFAULT 1 NOT NULL,
    plantilla_frente character varying(255) DEFAULT 'assets/img/plantilla_frente.jpg'::character varying NOT NULL,
    plantilla_reverso character varying(255) DEFAULT 'assets/img/plantilla_reverso.jpg'::character varying NOT NULL,
    libro_numero character varying(100) DEFAULT 'Libro 1'::character varying NOT NULL,
    folio_inicial integer DEFAULT 1 NOT NULL,
    linea_inicial integer DEFAULT 1 NOT NULL,
    folio_actual integer DEFAULT 1 NOT NULL,
    linea_actual integer DEFAULT 1 NOT NULL,
    id_autoridad integer,
    fecha_actualizacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT certificado_configuracion_id_config_check CHECK ((id_config = 1))
);


--
-- Name: certificado_envios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.certificado_envios (
    id_envio bigint NOT NULL,
    id_certificado integer NOT NULL,
    correo character varying(255) NOT NULL,
    estatus character varying(20) DEFAULT 'Pendiente'::character varying NOT NULL,
    mensaje text,
    fecha_envio timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: certificado_envios_id_envio_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.certificado_envios_id_envio_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: certificado_envios_id_envio_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.certificado_envios_id_envio_seq OWNED BY public.certificado_envios.id_envio;


--
-- Name: certificados_emitidos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.certificados_emitidos (
    id_certificado integer NOT NULL,
    id_inscripcion integer,
    id_estudiante integer NOT NULL,
    id_malla integer,
    tipo character varying(30) DEFAULT 'Culminacion'::character varying NOT NULL,
    folio character varying(60) NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    id_usuario_emisor integer,
    estado character varying(20) DEFAULT 'Emitido'::character varying NOT NULL,
    libro character varying(100) DEFAULT 'Libro 1'::character varying NOT NULL,
    linea_libro integer DEFAULT 0 NOT NULL,
    codigo_validacion character varying(40),
    origen character varying(20) DEFAULT 'Academico'::character varying NOT NULL,
    id_foro integer
);


--
-- Name: certificados_emitidos_id_certificado_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.certificados_emitidos_id_certificado_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: certificados_emitidos_id_certificado_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.certificados_emitidos_id_certificado_seq OWNED BY public.certificados_emitidos.id_certificado;


--
-- Name: cohortes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cohortes (
    id_cohorte integer NOT NULL,
    codigo_cohorte character varying(50) NOT NULL,
    id_malla integer,
    id_profesor_guia integer,
    salon_aula character varying(100) NOT NULL,
    dias_clase character varying(100) NOT NULL,
    horas_diarias integer DEFAULT 4 NOT NULL,
    fecha_inicio date NOT NULL,
    fecha_fin_estimada date,
    cupos_maximos integer DEFAULT 30,
    estatus character varying(20) DEFAULT 'Planificada'::character varying,
    num_periodo integer DEFAULT 1,
    hora_inicio time without time zone,
    hora_fin time without time zone,
    inscripcion_pernocta_abierta boolean DEFAULT false NOT NULL,
    CONSTRAINT cohortes_estatus_check CHECK (((estatus)::text = ANY (ARRAY[('Planificada'::character varying)::text, ('En Curso'::character varying)::text, ('Finalizada'::character varying)::text, ('Cancelada'::character varying)::text])))
);


--
-- Name: COLUMN cohortes.inscripcion_pernocta_abierta; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.cohortes.inscripcion_pernocta_abierta IS 'Habilita el pre-registro por cédula de estudiantes para un evento con pernocta.';


--
-- Name: cohortes_id_cohorte_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cohortes_id_cohorte_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cohortes_id_cohorte_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cohortes_id_cohorte_seq OWNED BY public.cohortes.id_cohorte;


--
-- Name: comedor_asistencia; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comedor_asistencia (
    id_asistencia integer NOT NULL,
    id_usuario integer,
    fecha_registro date DEFAULT CURRENT_DATE,
    hora_registro time without time zone DEFAULT CURRENT_TIME,
    turno character varying(20) NOT NULL
);


--
-- Name: comedor_asistencia_id_asistencia_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.comedor_asistencia_id_asistencia_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: comedor_asistencia_id_asistencia_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.comedor_asistencia_id_asistencia_seq OWNED BY public.comedor_asistencia.id_asistencia;


--
-- Name: comedor_inventario; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comedor_inventario (
    id_producto integer NOT NULL,
    nombre_producto character varying(150) NOT NULL,
    categoria character varying(50) NOT NULL,
    existencia_actual numeric(10,2) DEFAULT 0.00,
    unidad_medida character varying(20) NOT NULL,
    nivel_minimo numeric(10,2) DEFAULT 5.00,
    fecha_actualizacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    codigo_alimento_inn integer
);


--
-- Name: comedor_inventario_id_producto_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.comedor_inventario_id_producto_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: comedor_inventario_id_producto_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.comedor_inventario_id_producto_seq OWNED BY public.comedor_inventario.id_producto;


--
-- Name: comedor_menu; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comedor_menu (
    id_menu integer NOT NULL,
    fecha date DEFAULT CURRENT_DATE NOT NULL,
    turno_comida character varying(20) NOT NULL,
    descripcion_plato text NOT NULL,
    estatus character varying(20) DEFAULT 'Publicado'::character varying,
    CONSTRAINT comedor_menu_turno_comida_check CHECK (((turno_comida)::text = ANY (ARRAY[('Desayuno'::character varying)::text, ('Almuerzo'::character varying)::text, ('Merienda'::character varying)::text, ('Cena'::character varying)::text])))
);


--
-- Name: comedor_menu_id_menu_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.comedor_menu_id_menu_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: comedor_menu_id_menu_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.comedor_menu_id_menu_seq OWNED BY public.comedor_menu.id_menu;


--
-- Name: comedor_movimientos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comedor_movimientos (
    id_movimiento integer NOT NULL,
    id_producto integer,
    tipo_movimiento character varying(10),
    cantidad numeric(10,2) NOT NULL,
    motivo_observacion text,
    id_usuario_registro integer,
    fecha_movimiento timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT comedor_movimientos_tipo_movimiento_check CHECK (((tipo_movimiento)::text = ANY (ARRAY[('Entrada'::character varying)::text, ('Salida'::character varying)::text, ('Ajuste'::character varying)::text])))
);


--
-- Name: comedor_movimientos_id_movimiento_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.comedor_movimientos_id_movimiento_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: comedor_movimientos_id_movimiento_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.comedor_movimientos_id_movimiento_seq OWNED BY public.comedor_movimientos.id_movimiento;


--
-- Name: configuracion_autoridades; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.configuracion_autoridades (
    id_autoridad integer NOT NULL,
    cargo character varying(100) NOT NULL,
    nombre_apellido character varying(150) NOT NULL,
    cedula character varying(20) NOT NULL,
    tipo_nombramiento character varying(80) NOT NULL,
    numero_nombramiento character varying(100) NOT NULL,
    fecha_gaceta_nombramiento date,
    firma_digital character varying(255) DEFAULT 'assets/img/firmas/default_firma.png'::character varying,
    fecha_actualizacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    estatus character varying(20) DEFAULT 'Activo'::character varying,
    fecha_inicio_gestion date DEFAULT CURRENT_DATE,
    fecha_fin_gestion date
);


--
-- Name: configuracion_autoridades_id_autoridad_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.configuracion_autoridades_id_autoridad_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: configuracion_autoridades_id_autoridad_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.configuracion_autoridades_id_autoridad_seq OWNED BY public.configuracion_autoridades.id_autoridad;


--
-- Name: cursos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cursos (
    id_curso integer NOT NULL,
    nombre_curso character varying(150) NOT NULL,
    id_profesor integer,
    plan_evaluacion text,
    horas_academicas integer DEFAULT 0,
    cupos_maximos integer DEFAULT 30,
    fecha_inicio date,
    fecha_fin date,
    estatus_curso character varying(20) DEFAULT 'Activo'::character varying,
    fecha_creacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT cursos_estatus_curso_check CHECK (((estatus_curso)::text = ANY (ARRAY[('Activo'::character varying)::text, ('Finalizado'::character varying)::text, ('Cancelado'::character varying)::text])))
);


--
-- Name: cursos_id_curso_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cursos_id_curso_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cursos_id_curso_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cursos_id_curso_seq OWNED BY public.cursos.id_curso;


--
-- Name: defensoria_casos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.defensoria_casos (
    id_caso integer NOT NULL,
    codigo_expediente character varying(50) NOT NULL,
    cedula_victimaria_afectada character varying(20) NOT NULL,
    nombre_victima character varying(150) NOT NULL,
    telefono_victima character varying(30),
    direccion_victima text,
    tipo_violencia character varying(100) NOT NULL,
    descripcion_hechos text NOT NULL,
    estatus_caso character varying(30) DEFAULT 'En Proceso'::character varying,
    id_defensora integer,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT defensoria_casos_estatus_caso_check CHECK (((estatus_caso)::text = ANY (ARRAY[('En Proceso'::character varying)::text, ('Cerrado'::character varying)::text, ('Remitido'::character varying)::text])))
);


--
-- Name: defensoria_casos_id_caso_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.defensoria_casos_id_caso_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: defensoria_casos_id_caso_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.defensoria_casos_id_caso_seq OWNED BY public.defensoria_casos.id_caso;


--
-- Name: defensoria_seguimiento; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.defensoria_seguimiento (
    id_seguimiento integer NOT NULL,
    id_caso integer,
    observacion text NOT NULL,
    acuerdos_compromisos text,
    id_responsable integer,
    fecha_atencion timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: defensoria_seguimiento_id_seguimiento_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.defensoria_seguimiento_id_seguimiento_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: defensoria_seguimiento_id_seguimiento_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.defensoria_seguimiento_id_seguimiento_seq OWNED BY public.defensoria_seguimiento.id_seguimiento;


--
-- Name: discapacidad; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.discapacidad (
    id integer NOT NULL,
    nombre character varying(255) NOT NULL,
    activo integer DEFAULT 1 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: discapacidad_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.discapacidad_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: discapacidad_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.discapacidad_id_seq OWNED BY public.discapacidad.id;


--
-- Name: dormitorio_lista_espera; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dormitorio_lista_espera (
    id_lista_espera bigint NOT NULL,
    id_cohorte integer,
    id_usuario integer,
    cedula text NOT NULL,
    nombre_completo text NOT NULL,
    telefono text,
    fecha_solicitud timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    estatus text DEFAULT 'En Espera'::text NOT NULL,
    id_pernocta_asignada integer,
    id_usuario_registro integer,
    observaciones text,
    id_foro integer,
    CONSTRAINT chk_lista_espera_evento CHECK (((id_foro IS NOT NULL) OR (id_cohorte IS NOT NULL))),
    CONSTRAINT dormitorio_lista_espera_estatus_check CHECK ((estatus = ANY (ARRAY['En Espera'::text, 'Asignada'::text, 'Cancelada'::text])))
);


--
-- Name: TABLE dormitorio_lista_espera; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dormitorio_lista_espera IS 'Personas esperando cama en un evento con pernocta cuando no hay disponibilidad.';


--
-- Name: dormitorio_lista_espera_id_lista_espera_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dormitorio_lista_espera_id_lista_espera_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dormitorio_lista_espera_id_lista_espera_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dormitorio_lista_espera_id_lista_espera_seq OWNED BY public.dormitorio_lista_espera.id_lista_espera;


--
-- Name: dormitorio_mudanzas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dormitorio_mudanzas (
    id_mudanza bigint NOT NULL,
    id_pernocta integer NOT NULL,
    id_dormitorio_origen integer NOT NULL,
    id_dormitorio_destino integer NOT NULL,
    fecha_mudanza timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    motivo text NOT NULL,
    id_usuario_registro integer,
    CONSTRAINT dormitorio_mudanzas_distintos CHECK ((id_dormitorio_origen <> id_dormitorio_destino))
);


--
-- Name: TABLE dormitorio_mudanzas; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dormitorio_mudanzas IS 'Registro de mudanzas de un huésped de un dormitorio a otro.';


--
-- Name: dormitorio_mudanzas_id_mudanza_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dormitorio_mudanzas_id_mudanza_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dormitorio_mudanzas_id_mudanza_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dormitorio_mudanzas_id_mudanza_seq OWNED BY public.dormitorio_mudanzas.id_mudanza;


--
-- Name: dormitorio_pernoctas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dormitorio_pernoctas (
    id_pernocta integer NOT NULL,
    id_dormitorio integer NOT NULL,
    id_usuario integer,
    es_visitante_foraneo boolean DEFAULT false NOT NULL,
    nombre_completo character varying(150) NOT NULL,
    cedula character varying(20),
    telefono character varying(30),
    id_cohorte integer,
    fecha_entrada date DEFAULT CURRENT_DATE NOT NULL,
    hora_entrada time without time zone DEFAULT CURRENT_TIME NOT NULL,
    fecha_salida_prevista date NOT NULL,
    fecha_salida_real timestamp without time zone,
    estado character varying(20) DEFAULT 'En Curso'::character varying NOT NULL,
    motivo character varying(255),
    observaciones character varying(255),
    id_usuario_registro integer,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    id_foro integer,
    CONSTRAINT dormitorio_pernoctas_estado_check CHECK (((estado)::text = ANY (ARRAY[('En Curso'::character varying)::text, ('Finalizada'::character varying)::text])))
);


--
-- Name: TABLE dormitorio_pernoctas; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dormitorio_pernoctas IS 'Registro de pernoctas por habitación. Huésped = usuario del sistema o visitante foráneo con datos propios.';


--
-- Name: COLUMN dormitorio_pernoctas.es_visitante_foraneo; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.dormitorio_pernoctas.es_visitante_foraneo IS 'TRUE cuando el huésped no tiene cuenta en el sistema (visitante externo registrado con sus datos).';


--
-- Name: COLUMN dormitorio_pernoctas.fecha_salida_prevista; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.dormitorio_pernoctas.fecha_salida_prevista IS 'Si el estudiante permanece después de las 18:00, la pernocta se cierra al siguiente día. Extensible con prórroga.';


--
-- Name: COLUMN dormitorio_pernoctas.id_foro; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.dormitorio_pernoctas.id_foro IS 'Evento extraordinario (Foro/Conversatorio) al que asiste la persona pernoctada.';


--
-- Name: dormitorio_pernoctas_id_pernocta_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dormitorio_pernoctas_id_pernocta_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dormitorio_pernoctas_id_pernocta_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dormitorio_pernoctas_id_pernocta_seq OWNED BY public.dormitorio_pernoctas.id_pernocta;


--
-- Name: dormitorio_prorrogas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dormitorio_prorrogas (
    id_prorroga integer NOT NULL,
    id_pernocta integer NOT NULL,
    dias_concedidos integer DEFAULT 1 NOT NULL,
    fecha_salida_anterior date NOT NULL,
    fecha_salida_nueva date NOT NULL,
    motivo character varying(255),
    id_usuario integer,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT dormitorio_prorrogas_dias_concedidos_check CHECK (((dias_concedidos >= 1) AND (dias_concedidos <= 30)))
);


--
-- Name: TABLE dormitorio_prorrogas; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dormitorio_prorrogas IS 'Prórrogas de salida de pernocta (transporte no llegó, accidente, dificultad, etc.).';


--
-- Name: dormitorio_prorrogas_id_prorroga_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dormitorio_prorrogas_id_prorroga_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dormitorio_prorrogas_id_prorroga_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dormitorio_prorrogas_id_prorroga_seq OWNED BY public.dormitorio_prorrogas.id_prorroga;


--
-- Name: dormitorio_responsables; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dormitorio_responsables (
    id_responsable_registro bigint NOT NULL,
    id_dormitorio integer NOT NULL,
    id_usuario integer NOT NULL,
    fecha_desde timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_hasta timestamp without time zone,
    motivo_cambio text,
    id_usuario_registro integer
);


--
-- Name: TABLE dormitorio_responsables; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dormitorio_responsables IS 'Historial de responsables de cuarto. Solo uno activo (fecha_hasta IS NULL) por dormitorio.';


--
-- Name: dormitorio_responsables_id_responsable_registro_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dormitorio_responsables_id_responsable_registro_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dormitorio_responsables_id_responsable_registro_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dormitorio_responsables_id_responsable_registro_seq OWNED BY public.dormitorio_responsables.id_responsable_registro;


--
-- Name: dormitorios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dormitorios (
    id_dormitorio integer NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion character varying(255),
    capacidad_camas integer NOT NULL,
    id_responsable integer,
    telefono_responsable character varying(30),
    estatus character varying(20) DEFAULT 'Disponible'::character varying NOT NULL,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_ultima_responsable timestamp without time zone,
    planta character varying(80),
    CONSTRAINT dormitorios_capacidad_camas_check CHECK ((capacidad_camas > 0)),
    CONSTRAINT dormitorios_estatus_check CHECK (((estatus)::text = ANY (ARRAY[('Disponible'::character varying)::text, ('Ocupada'::character varying)::text, ('En Mantenimiento'::character varying)::text, ('Inactiva'::character varying)::text])))
);


--
-- Name: TABLE dormitorios; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dormitorios IS 'Habitaciones de la infraestructura con capacidad de camas y responsable con teléfono.';


--
-- Name: COLUMN dormitorios.planta; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.dormitorios.planta IS 'Planta física del edificio; en una misma planta pueden existir varios dormitorios.';


--
-- Name: dormitorios_id_dormitorio_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dormitorios_id_dormitorio_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dormitorios_id_dormitorio_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dormitorios_id_dormitorio_seq OWNED BY public.dormitorios.id_dormitorio;


--
-- Name: etnia; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.etnia (
    id integer NOT NULL,
    nombre character varying(255) NOT NULL,
    activo integer DEFAULT 1 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: etnia_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.etnia_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: etnia_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.etnia_id_seq OWNED BY public.etnia.id;


--
-- Name: feriados_venezuela; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feriados_venezuela (
    id_feriado integer NOT NULL,
    fecha date NOT NULL,
    descripcion character varying(150) NOT NULL
);


--
-- Name: feriados_venezuela_id_feriado_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.feriados_venezuela_id_feriado_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: feriados_venezuela_id_feriado_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.feriados_venezuela_id_feriado_seq OWNED BY public.feriados_venezuela.id_feriado;


--
-- Name: foro_asistencias; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foro_asistencias (
    id_asistencia integer NOT NULL,
    id_participante integer NOT NULL,
    tipo_asistencia character varying(30) NOT NULL,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    registrado_por integer,
    observaciones text,
    CONSTRAINT foro_asistencias_tipo_asistencia_check CHECK (((tipo_asistencia)::text = ANY ((ARRAY['Ponencia'::character varying, 'Mesa de Trabajo'::character varying])::text[])))
);


--
-- Name: foro_asistencias_id_asistencia_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.foro_asistencias_id_asistencia_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: foro_asistencias_id_asistencia_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.foro_asistencias_id_asistencia_seq OWNED BY public.foro_asistencias.id_asistencia;


--
-- Name: foro_certificados; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foro_certificados (
    id_certificado_foro integer NOT NULL,
    id_participante integer NOT NULL,
    rol_certificado character varying(20) NOT NULL,
    id_certificado_emitido integer,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    id_usuario_emisor integer,
    CONSTRAINT foro_certificados_rol_certificado_check CHECK (((rol_certificado)::text = ANY ((ARRAY['Oyente'::character varying, 'Participante'::character varying])::text[])))
);


--
-- Name: foro_certificados_id_certificado_foro_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.foro_certificados_id_certificado_foro_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: foro_certificados_id_certificado_foro_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.foro_certificados_id_certificado_foro_seq OWNED BY public.foro_certificados.id_certificado_foro;


--
-- Name: foro_informes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foro_informes (
    id_informe integer NOT NULL,
    id_mesa integer NOT NULL,
    id_participante integer NOT NULL,
    contenido text NOT NULL,
    estatus character varying(15) DEFAULT 'Borrador'::character varying NOT NULL,
    fecha_envio timestamp without time zone,
    fecha_actualizacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT foro_informes_estatus_check CHECK (((estatus)::text = ANY ((ARRAY['Borrador'::character varying, 'Enviado'::character varying])::text[])))
);


--
-- Name: foro_informes_id_informe_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.foro_informes_id_informe_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: foro_informes_id_informe_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.foro_informes_id_informe_seq OWNED BY public.foro_informes.id_informe;


--
-- Name: foro_mesa_participantes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foro_mesa_participantes (
    id_mesa_participante integer NOT NULL,
    id_mesa integer NOT NULL,
    id_participante integer NOT NULL,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: foro_mesa_participantes_id_mesa_participante_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.foro_mesa_participantes_id_mesa_participante_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: foro_mesa_participantes_id_mesa_participante_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.foro_mesa_participantes_id_mesa_participante_seq OWNED BY public.foro_mesa_participantes.id_mesa_participante;


--
-- Name: foro_mesas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foro_mesas (
    id_mesa integer NOT NULL,
    id_foro integer NOT NULL,
    numero_mesa integer NOT NULL,
    nombre_mesa character varying(150),
    id_transcriptor integer,
    capacidad integer DEFAULT 10 NOT NULL,
    estatus character varying(15) DEFAULT 'Abierta'::character varying NOT NULL,
    informe_transcriptor text,
    fecha_informe_transcriptor timestamp without time zone,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT foro_mesas_capacidad_check CHECK ((capacidad > 0)),
    CONSTRAINT foro_mesas_estatus_check CHECK (((estatus)::text = ANY ((ARRAY['Abierta'::character varying, 'Cerrada'::character varying])::text[]))),
    CONSTRAINT foro_mesas_numero_mesa_check CHECK ((numero_mesa > 0))
);


--
-- Name: COLUMN foro_mesas.id_transcriptor; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.foro_mesas.id_transcriptor IS 'Integrante de la mesa habilitado como transcriptor; se asigna después de inscribir a los participantes.';


--
-- Name: foro_mesas_id_mesa_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.foro_mesas_id_mesa_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: foro_mesas_id_mesa_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.foro_mesas_id_mesa_seq OWNED BY public.foro_mesas.id_mesa;


--
-- Name: foro_participantes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foro_participantes (
    id_participante integer NOT NULL,
    id_foro integer NOT NULL,
    id_estudiante integer NOT NULL,
    estatus character varying(20) DEFAULT 'Inscrito'::character varying NOT NULL,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    id_usuario_registro integer,
    observaciones text,
    CONSTRAINT foro_participantes_estatus_check CHECK (((estatus)::text = ANY ((ARRAY['Inscrito'::character varying, 'Asistente'::character varying, 'Participante'::character varying])::text[])))
);


--
-- Name: foro_participantes_id_participante_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.foro_participantes_id_participante_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: foro_participantes_id_participante_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.foro_participantes_id_participante_seq OWNED BY public.foro_participantes.id_participante;


--
-- Name: foro_temas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foro_temas (
    id_tema integer NOT NULL,
    id_foro integer NOT NULL,
    nombre_tema character varying(255) NOT NULL,
    ponente character varying(255),
    horas_academicas integer DEFAULT 0 NOT NULL,
    contenido text,
    orden_tema integer DEFAULT 1 NOT NULL,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT foro_temas_horas_academicas_check CHECK ((horas_academicas >= 0))
);


--
-- Name: foro_temas_id_tema_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.foro_temas_id_tema_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: foro_temas_id_tema_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.foro_temas_id_tema_seq OWNED BY public.foro_temas.id_tema;


--
-- Name: foros_eventos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.foros_eventos (
    id_foro integer NOT NULL,
    codigo_foro character varying(50) NOT NULL,
    nombre character varying(255) NOT NULL,
    tipo_foro character varying(30) DEFAULT 'Foro'::character varying NOT NULL,
    descripcion text,
    fecha_inicio date,
    fecha_fin date,
    lugar character varying(255),
    horas_academicas integer DEFAULT 0 NOT NULL,
    id_malla integer,
    estatus character varying(20) DEFAULT 'Planificado'::character varying NOT NULL,
    id_usuario_creador integer,
    fecha_creacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    pernocta_habilitada boolean DEFAULT false NOT NULL,
    CONSTRAINT foros_eventos_estatus_check CHECK (((estatus)::text = ANY ((ARRAY['Planificado'::character varying, 'Activo'::character varying, 'Finalizado'::character varying])::text[]))),
    CONSTRAINT foros_eventos_horas_academicas_check CHECK ((horas_academicas >= 0)),
    CONSTRAINT foros_eventos_tipo_foro_check CHECK (((tipo_foro)::text = ANY ((ARRAY['Foro'::character varying, 'Conversatorio'::character varying, 'Seminario'::character varying])::text[])))
);


--
-- Name: TABLE foros_eventos; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.foros_eventos IS 'Eventos extraordinarios (Foros/Conversatorios) con malla, participantes, mesas y certificados propios.';


--
-- Name: foros_eventos_id_foro_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.foros_eventos_id_foro_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: foros_eventos_id_foro_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.foros_eventos_id_foro_seq OWNED BY public.foros_eventos.id_foro;


--
-- Name: guarderia_asistencia; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.guarderia_asistencia (
    id_asistencia integer NOT NULL,
    id_nino integer,
    fecha_asistencia date DEFAULT CURRENT_DATE,
    hora_entrada time without time zone DEFAULT CURRENT_TIME,
    hora_salida time without time zone,
    estatus character varying(20) DEFAULT 'Presente'::character varying,
    CONSTRAINT guarderia_asistencia_estatus_check CHECK (((estatus)::text = ANY (ARRAY[('Presente'::character varying)::text, ('Retirado'::character varying)::text])))
);


--
-- Name: guarderia_asistencia_id_asistencia_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.guarderia_asistencia_id_asistencia_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: guarderia_asistencia_id_asistencia_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.guarderia_asistencia_id_asistencia_seq OWNED BY public.guarderia_asistencia.id_asistencia;


--
-- Name: guarderia_ninos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.guarderia_ninos (
    id_nino integer NOT NULL,
    id_representante integer,
    nombre_nino character varying(100) NOT NULL,
    apellido_nino character varying(100) NOT NULL,
    fecha_nacimiento date NOT NULL,
    sexo character varying(10),
    observaciones text,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT guarderia_ninos_sexo_check CHECK (((sexo)::text = ANY (ARRAY[('Femenino'::character varying)::text, ('Masculino'::character varying)::text])))
);


--
-- Name: guarderia_ninos_id_nino_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.guarderia_ninos_id_nino_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: guarderia_ninos_id_nino_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.guarderia_ninos_id_nino_seq OWNED BY public.guarderia_ninos.id_nino;


--
-- Name: horarios_clases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.horarios_clases (
    id_horario integer NOT NULL,
    id_cohorte integer NOT NULL,
    id_materia integer,
    nombre_materia character varying(150) NOT NULL,
    dia_semana character varying(20) NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    orden integer DEFAULT 0,
    fecha_generacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: horarios_clases_id_horario_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.horarios_clases_id_horario_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: horarios_clases_id_horario_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.horarios_clases_id_horario_seq OWNED BY public.horarios_clases.id_horario;


--
-- Name: horarios_secciones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.horarios_secciones (
    id_horario integer NOT NULL,
    id_cohorte integer NOT NULL,
    id_materia integer NOT NULL,
    id_profesor integer,
    dia_semana character varying(15) NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    salon_aula character varying(100),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: horarios_secciones_id_horario_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.horarios_secciones_id_horario_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: horarios_secciones_id_horario_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.horarios_secciones_id_horario_seq OWNED BY public.horarios_secciones.id_horario;


--
-- Name: inscripciones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inscripciones (
    id_inscripcion integer NOT NULL,
    id_curso integer,
    id_estudiante integer,
    fecha_inscripcion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    nota_cualitativa character varying(50) DEFAULT 'En Progreso'::character varying,
    folio_certificado character varying(100),
    id_etnia integer,
    id_discapacidad integer
);


--
-- Name: inscripciones_id_inscripcion_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inscripciones_id_inscripcion_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inscripciones_id_inscripcion_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inscripciones_id_inscripcion_seq OWNED BY public.inscripciones.id_inscripcion;


--
-- Name: inscripciones_respaldo_20261119; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inscripciones_respaldo_20261119 (
    id_inscripcion integer,
    id_curso integer,
    id_estudiante integer,
    fecha_inscripcion timestamp without time zone,
    nota_cualitativa character varying(50),
    folio_certificado character varying(100),
    id_etnia integer,
    id_discapacidad integer
);


--
-- Name: login_intentos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.login_intentos (
    id bigint NOT NULL,
    ip character varying(45) NOT NULL,
    cedula character varying(20) NOT NULL,
    exito boolean DEFAULT false NOT NULL,
    fecha timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: TABLE login_intentos; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.login_intentos IS 'Registro de intentos de login para control de fuerza bruta (5 fallos en 15 min = bloqueo temporal por IP + cedula)';


--
-- Name: login_intentos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.login_intentos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: login_intentos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.login_intentos_id_seq OWNED BY public.login_intentos.id;


--
-- Name: malla_materias; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.malla_materias (
    id_materia integer NOT NULL,
    id_malla integer,
    modulo_num integer NOT NULL,
    nombre_materia character varying(150) NOT NULL,
    contenido_detallado text NOT NULL,
    horas_academicas integer DEFAULT 8 NOT NULL
);


--
-- Name: malla_materias_id_materia_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.malla_materias_id_materia_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: malla_materias_id_materia_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.malla_materias_id_materia_seq OWNED BY public.malla_materias.id_materia;


--
-- Name: mallas_curriculares; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mallas_curriculares (
    id_malla integer NOT NULL,
    nombre_programa character varying(200) NOT NULL,
    tipo_oferta character varying(50) NOT NULL,
    requiere_pernocta boolean DEFAULT false,
    duracion_dias_estimada integer DEFAULT 0,
    descripcion text,
    fecha_creacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    tipo_regimen character varying(50) DEFAULT 'Módulo'::character varying,
    total_periodos integer DEFAULT 1,
    CONSTRAINT mallas_curriculares_tipo_oferta_check CHECK (((tipo_oferta)::text = ANY (ARRAY['Curso'::text, 'Diplomado Intensivo'::text, 'Foro / Taller'::text, 'Foro Virtual'::text, 'Encuentro'::text, 'Carrera Larga'::text, 'Carrera Corta'::text, 'Conversatorio'::text, 'Foros'::text, 'Seminarios'::text, 'Educación Básica'::text, 'Bachillerato'::text, 'TSU'::text, 'Pregrado'::text, 'Postgrado'::text, 'Maestría'::text, 'Doctorado'::text])))
);


--
-- Name: mallas_curriculares_id_malla_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.mallas_curriculares_id_malla_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: mallas_curriculares_id_malla_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.mallas_curriculares_id_malla_seq OWNED BY public.mallas_curriculares.id_malla;


--
-- Name: materia_contenidos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.materia_contenidos (
    id_contenido integer NOT NULL,
    id_materia integer NOT NULL,
    descripcion text NOT NULL,
    horas_academicas integer DEFAULT 0 NOT NULL,
    orden integer DEFAULT 1 NOT NULL
);


--
-- Name: materia_contenidos_id_contenido_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.materia_contenidos_id_contenido_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: materia_contenidos_id_contenido_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.materia_contenidos_id_contenido_seq OWNED BY public.materia_contenidos.id_contenido;


--
-- Name: misiones_estudiantes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.misiones_estudiantes (
    id_estudiante_mision integer NOT NULL,
    id_prestamo integer,
    cedula character varying(20) NOT NULL,
    nombre character varying(100) NOT NULL,
    apellido character varying(100) NOT NULL,
    fecha_nacimiento date,
    edad integer,
    estado_civil character varying(30) DEFAULT 'Soltero(a)'::character varying,
    telefono character varying(30),
    id_etnia integer,
    id_discapacidad integer,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: misiones_estudiantes_id_estudiante_mision_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.misiones_estudiantes_id_estudiante_mision_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: misiones_estudiantes_id_estudiante_mision_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.misiones_estudiantes_id_estudiante_mision_seq OWNED BY public.misiones_estudiantes.id_estudiante_mision;


--
-- Name: monitor_sincronizaciones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.monitor_sincronizaciones (
    uuid_cliente character varying(64) NOT NULL,
    id_usuario integer NOT NULL,
    modulo character varying(30) NOT NULL,
    accion character varying(40) NOT NULL,
    fecha_cliente timestamp without time zone,
    fecha_servidor timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    resultado text
);


--
-- Name: notas_estudiantes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notas_estudiantes (
    id_nota integer NOT NULL,
    id_plan integer,
    id_estudiante integer,
    nota_cuantitativa numeric(5,2) DEFAULT NULL::numeric,
    nota_cualitativa character varying(50) DEFAULT 'Aprobado'::character varying,
    nota_recuperacion numeric(5,2) DEFAULT NULL::numeric,
    observaciones text,
    CONSTRAINT notas_cuantitativa_rango_check CHECK (((nota_cuantitativa IS NULL) OR ((nota_cuantitativa >= (1)::numeric) AND (nota_cuantitativa <= (20)::numeric)))),
    CONSTRAINT notas_recuperacion_rango_check CHECK (((nota_recuperacion IS NULL) OR ((nota_recuperacion >= (1)::numeric) AND (nota_recuperacion <= (20)::numeric))))
);


--
-- Name: notas_estudiantes_id_nota_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notas_estudiantes_id_nota_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notas_estudiantes_id_nota_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notas_estudiantes_id_nota_seq OWNED BY public.notas_estudiantes.id_nota;


--
-- Name: plan_evaluacion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_evaluacion (
    id_plan integer NOT NULL,
    id_cohorte integer,
    id_materia integer,
    titulo_evaluacion character varying(150) NOT NULL,
    tipo_ponderacion character varying(20) DEFAULT 'Cuantitativa'::character varying,
    porcentaje_peso numeric(5,2) DEFAULT 0.00,
    es_asistencia boolean DEFAULT false,
    fecha_evaluacion date,
    id_profesor integer,
    descripcion_libre text,
    instrumento_evaluacion character varying(120),
    CONSTRAINT plan_evaluacion_peso_check CHECK (((porcentaje_peso > (0)::numeric) AND (porcentaje_peso <= (100)::numeric))),
    CONSTRAINT plan_evaluacion_tipo_ponderacion_check CHECK (((tipo_ponderacion)::text = ANY (ARRAY[('Cuantitativa'::character varying)::text, ('Cualitativa'::character varying)::text])))
);


--
-- Name: plan_evaluacion_contenidos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_evaluacion_contenidos (
    id_plan_contenido integer NOT NULL,
    id_plan integer NOT NULL,
    id_contenido integer NOT NULL
);


--
-- Name: plan_evaluacion_contenidos_id_plan_contenido_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.plan_evaluacion_contenidos_id_plan_contenido_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_evaluacion_contenidos_id_plan_contenido_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.plan_evaluacion_contenidos_id_plan_contenido_seq OWNED BY public.plan_evaluacion_contenidos.id_plan_contenido;


--
-- Name: plan_evaluacion_id_plan_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.plan_evaluacion_id_plan_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_evaluacion_id_plan_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.plan_evaluacion_id_plan_seq OWNED BY public.plan_evaluacion.id_plan;


--
-- Name: prestamo_misiones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.prestamo_misiones (
    id_prestamo integer NOT NULL,
    nombre_mision character varying(255) NOT NULL,
    facilitador_responsable character varying(150) NOT NULL,
    cedula_facilitador character varying(20),
    telefono_facilitador character varying(30),
    cantidad_estudiantes integer DEFAULT 0,
    periodo_academico character varying(50) NOT NULL,
    salon_asignado character varying(100) NOT NULL,
    dias_horario character varying(150) NOT NULL,
    fecha_inicio date NOT NULL,
    fecha_fin date NOT NULL,
    observaciones text,
    fecha_nacimiento_facilitador date,
    edad_facilitador integer,
    estado_civil_facilitador character varying(30) DEFAULT 'Soltero(a)'::character varying
);


--
-- Name: prestamo_misiones_id_prestamo_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.prestamo_misiones_id_prestamo_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: prestamo_misiones_id_prestamo_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.prestamo_misiones_id_prestamo_seq OWNED BY public.prestamo_misiones.id_prestamo;


--
-- Name: recepcion_alertas_qr; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recepcion_alertas_qr (
    id_alerta integer NOT NULL,
    cedula character varying(20) NOT NULL,
    id_usuario integer,
    fecha_lectura date DEFAULT CURRENT_DATE NOT NULL,
    hora_lectura time without time zone DEFAULT CURRENT_TIME NOT NULL,
    tipo_alerta character varying(40) NOT NULL,
    area_destino character varying(100) NOT NULL,
    atendida boolean DEFAULT false NOT NULL,
    id_usuario_atencion integer,
    fecha_atencion timestamp without time zone,
    observaciones text,
    nombre_completo character varying(150),
    CONSTRAINT recepcion_alertas_qr_tipo_alerta_check CHECK (((tipo_alerta)::text = ANY ((ARRAY['USUARIO_NO_EXISTE'::character varying, 'SIN_INSCRIPCION'::character varying])::text[])))
);


--
-- Name: COLUMN recepcion_alertas_qr.nombre_completo; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.recepcion_alertas_qr.nombre_completo IS 'Nombre identificado por cédula (Padrón Nacional o usuarios del sistema).';


--
-- Name: recepcion_alertas_qr_id_alerta_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.recepcion_alertas_qr_id_alerta_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: recepcion_alertas_qr_id_alerta_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.recepcion_alertas_qr_id_alerta_seq OWNED BY public.recepcion_alertas_qr.id_alerta;


--
-- Name: recepcion_areas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recepcion_areas (
    id_area integer NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    activo boolean DEFAULT true NOT NULL
);


--
-- Name: recepcion_areas_id_area_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.recepcion_areas_id_area_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: recepcion_areas_id_area_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.recepcion_areas_id_area_seq OWNED BY public.recepcion_areas.id_area;


--
-- Name: recepcion_visitantes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recepcion_visitantes (
    id_visitante integer NOT NULL,
    es_usuario_sistema boolean DEFAULT false NOT NULL,
    id_usuario integer,
    cedula character varying(20) NOT NULL,
    nombre_completo character varying(150) NOT NULL,
    telefono character varying(30),
    entidad_origen character varying(150),
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fuente_datos character varying(30),
    fecha_nacimiento date,
    genero character varying(20)
);


--
-- Name: COLUMN recepcion_visitantes.fuente_datos; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.recepcion_visitantes.fuente_datos IS 'Origen de la identidad: PADRON_NACIONAL, SISTEMA o MANUAL.';


--
-- Name: COLUMN recepcion_visitantes.fecha_nacimiento; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.recepcion_visitantes.fecha_nacimiento IS 'Fecha de nacimiento tomada del Padrón Nacional, cuando está disponible.';


--
-- Name: COLUMN recepcion_visitantes.genero; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.recepcion_visitantes.genero IS 'Género normalizado tomado del Padrón Nacional, cuando está disponible.';


--
-- Name: recepcion_visitantes_id_visitante_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.recepcion_visitantes_id_visitante_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: recepcion_visitantes_id_visitante_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.recepcion_visitantes_id_visitante_seq OWNED BY public.recepcion_visitantes.id_visitante;


--
-- Name: recepcion_visitas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recepcion_visitas (
    id_visita integer NOT NULL,
    id_visitante integer NOT NULL,
    id_area_destino integer NOT NULL,
    id_cohorte integer,
    motivo character varying(255) NOT NULL,
    fecha_entrada date DEFAULT CURRENT_DATE NOT NULL,
    hora_entrada time without time zone DEFAULT CURRENT_TIME NOT NULL,
    fecha_salida date,
    hora_salida time without time zone,
    estatus character varying(20) DEFAULT 'En Curso'::character varying NOT NULL,
    id_usuario_registro integer,
    observaciones text,
    alerta_sin_curso boolean DEFAULT false NOT NULL,
    destino_recomendado character varying(100),
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT recepcion_visitas_estatus_check CHECK (((estatus)::text = ANY ((ARRAY['En Curso'::character varying, 'Finalizada'::character varying])::text[])))
);


--
-- Name: recepcion_visitas_id_visita_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.recepcion_visitas_id_visita_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: recepcion_visitas_id_visita_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.recepcion_visitas_id_visita_seq OWNED BY public.recepcion_visitas.id_visita;


--
-- Name: recuperacion_clave; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recuperacion_clave (
    id_recuperacion integer NOT NULL,
    id_usuario integer NOT NULL,
    codigo_verificacion character varying(10) NOT NULL,
    fecha_expiracion timestamp without time zone NOT NULL,
    usado boolean DEFAULT false
);


--
-- Name: recuperacion_clave_id_recuperacion_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.recuperacion_clave_id_recuperacion_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: recuperacion_clave_id_recuperacion_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.recuperacion_clave_id_recuperacion_seq OWNED BY public.recuperacion_clave.id_recuperacion;


--
-- Name: usuarios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuarios (
    id_usuario integer NOT NULL,
    cedula character varying(20) NOT NULL,
    nombre character varying(100) NOT NULL,
    apellido character varying(100),
    email character varying(150),
    password character varying(255) DEFAULT '$2y$10$e8R..BCRYPTHASHDEFAULTPASSWORD...'::character varying,
    telefono character varying(30),
    foto character varying(255) DEFAULT 'assets/img/user-default.png'::character varying,
    tipo_usuario character varying(50) NOT NULL,
    estatus_cuenta character varying(20) DEFAULT 'Pendiente'::character varying,
    id_municipio integer,
    fecha_registro timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    fecha_nacimiento date,
    sexo character varying(10),
    edad integer,
    estado_civil character varying(20),
    foto_perfil character varying(255) DEFAULT 'imagen_perfil_generico.jpg'::character varying,
    cargo_funcion character varying(100) DEFAULT 'Estudiante'::character varying,
    roles_asignados text[] DEFAULT ARRAY['estudiante'::text],
    etnia character varying(100) DEFAULT 'Ninguna'::character varying,
    tipo_discapacidad character varying(100) DEFAULT 'Ninguna'::character varying,
    estado character varying(100),
    municipio character varying(100),
    parroquia character varying(100),
    direccion_completa text,
    inscrito_cne character varying(2) DEFAULT 'No'::character varying,
    cne_estado character varying(100),
    cne_municipio character varying(100),
    cne_parroquia character varying(100),
    cne_centro_electoral character varying(200),
    tiene_hijos character varying(2) DEFAULT 'No'::character varying,
    cantidad_hijos integer DEFAULT 0,
    correo character varying(150),
    reset_token character varying(128),
    reset_expira timestamp without time zone,
    horas_semanales_min integer DEFAULT 20 NOT NULL,
    horas_semanales_max integer DEFAULT 40 NOT NULL,
    disponibilidad_horaria jsonb,
    espacios_permitidos jsonb,
    CONSTRAINT usuarios_estatus_cuenta_check CHECK (((estatus_cuenta)::text = ANY (ARRAY[('Pendiente'::character varying)::text, ('Activo'::character varying)::text, ('Inactivo'::character varying)::text]))),
    CONSTRAINT usuarios_horas_semanales_rango_chk CHECK (((horas_semanales_min >= 20) AND (horas_semanales_max <= 40) AND (horas_semanales_min <= horas_semanales_max))),
    CONSTRAINT usuarios_tipo_usuario_check CHECK (((tipo_usuario)::text = ANY (ARRAY['superadmin'::text, 'admin'::text, 'defensoria'::text, 'comedor'::text, 'cocina'::text, 'control_estudio'::text, 'profesor'::text, 'estudiante'::text, 'guarderia'::text, 'trabajador'::text, 'monitor'::text, 'recepcion'::text])))
);


--
-- Name: usuarios_id_usuario_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.usuarios_id_usuario_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usuarios_id_usuario_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.usuarios_id_usuario_seq OWNED BY public.usuarios.id_usuario;


--
-- Name: asignaciones_profesor id_asignacion; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asignaciones_profesor ALTER COLUMN id_asignacion SET DEFAULT nextval('public.asignaciones_profesor_id_asignacion_seq'::regclass);


--
-- Name: asistencia_clases id_asistencia_clase; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asistencia_clases ALTER COLUMN id_asistencia_clase SET DEFAULT nextval('public.asistencia_clases_id_asistencia_clase_seq'::regclass);


--
-- Name: bitacora id_log; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora ALTER COLUMN id_log SET DEFAULT nextval('public.bitacora_id_log_seq'::regclass);


--
-- Name: campus_edificios id_edificio; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campus_edificios ALTER COLUMN id_edificio SET DEFAULT nextval('public.campus_edificios_id_edificio_seq'::regclass);


--
-- Name: campus_espacios id_espacio; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campus_espacios ALTER COLUMN id_espacio SET DEFAULT nextval('public.campus_espacios_id_espacio_seq'::regclass);


--
-- Name: carreras_misiones id_carrera; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.carreras_misiones ALTER COLUMN id_carrera SET DEFAULT nextval('public.carreras_misiones_id_carrera_seq'::regclass);


--
-- Name: certificado_envios id_envio; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_envios ALTER COLUMN id_envio SET DEFAULT nextval('public.certificado_envios_id_envio_seq'::regclass);


--
-- Name: certificados_emitidos id_certificado; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificados_emitidos ALTER COLUMN id_certificado SET DEFAULT nextval('public.certificados_emitidos_id_certificado_seq'::regclass);


--
-- Name: cohortes id_cohorte; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cohortes ALTER COLUMN id_cohorte SET DEFAULT nextval('public.cohortes_id_cohorte_seq'::regclass);


--
-- Name: comedor_asistencia id_asistencia; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_asistencia ALTER COLUMN id_asistencia SET DEFAULT nextval('public.comedor_asistencia_id_asistencia_seq'::regclass);


--
-- Name: comedor_inventario id_producto; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_inventario ALTER COLUMN id_producto SET DEFAULT nextval('public.comedor_inventario_id_producto_seq'::regclass);


--
-- Name: comedor_menu id_menu; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_menu ALTER COLUMN id_menu SET DEFAULT nextval('public.comedor_menu_id_menu_seq'::regclass);


--
-- Name: comedor_movimientos id_movimiento; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_movimientos ALTER COLUMN id_movimiento SET DEFAULT nextval('public.comedor_movimientos_id_movimiento_seq'::regclass);


--
-- Name: configuracion_autoridades id_autoridad; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.configuracion_autoridades ALTER COLUMN id_autoridad SET DEFAULT nextval('public.configuracion_autoridades_id_autoridad_seq'::regclass);


--
-- Name: cursos id_curso; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cursos ALTER COLUMN id_curso SET DEFAULT nextval('public.cursos_id_curso_seq'::regclass);


--
-- Name: defensoria_casos id_caso; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defensoria_casos ALTER COLUMN id_caso SET DEFAULT nextval('public.defensoria_casos_id_caso_seq'::regclass);


--
-- Name: defensoria_seguimiento id_seguimiento; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defensoria_seguimiento ALTER COLUMN id_seguimiento SET DEFAULT nextval('public.defensoria_seguimiento_id_seguimiento_seq'::regclass);


--
-- Name: discapacidad id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discapacidad ALTER COLUMN id SET DEFAULT nextval('public.discapacidad_id_seq'::regclass);


--
-- Name: dormitorio_lista_espera id_lista_espera; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_lista_espera ALTER COLUMN id_lista_espera SET DEFAULT nextval('public.dormitorio_lista_espera_id_lista_espera_seq'::regclass);


--
-- Name: dormitorio_mudanzas id_mudanza; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_mudanzas ALTER COLUMN id_mudanza SET DEFAULT nextval('public.dormitorio_mudanzas_id_mudanza_seq'::regclass);


--
-- Name: dormitorio_pernoctas id_pernocta; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_pernoctas ALTER COLUMN id_pernocta SET DEFAULT nextval('public.dormitorio_pernoctas_id_pernocta_seq'::regclass);


--
-- Name: dormitorio_prorrogas id_prorroga; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_prorrogas ALTER COLUMN id_prorroga SET DEFAULT nextval('public.dormitorio_prorrogas_id_prorroga_seq'::regclass);


--
-- Name: dormitorio_responsables id_responsable_registro; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_responsables ALTER COLUMN id_responsable_registro SET DEFAULT nextval('public.dormitorio_responsables_id_responsable_registro_seq'::regclass);


--
-- Name: dormitorios id_dormitorio; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorios ALTER COLUMN id_dormitorio SET DEFAULT nextval('public.dormitorios_id_dormitorio_seq'::regclass);


--
-- Name: etnia id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.etnia ALTER COLUMN id SET DEFAULT nextval('public.etnia_id_seq'::regclass);


--
-- Name: feriados_venezuela id_feriado; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feriados_venezuela ALTER COLUMN id_feriado SET DEFAULT nextval('public.feriados_venezuela_id_feriado_seq'::regclass);


--
-- Name: foro_asistencias id_asistencia; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_asistencias ALTER COLUMN id_asistencia SET DEFAULT nextval('public.foro_asistencias_id_asistencia_seq'::regclass);


--
-- Name: foro_certificados id_certificado_foro; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_certificados ALTER COLUMN id_certificado_foro SET DEFAULT nextval('public.foro_certificados_id_certificado_foro_seq'::regclass);


--
-- Name: foro_informes id_informe; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_informes ALTER COLUMN id_informe SET DEFAULT nextval('public.foro_informes_id_informe_seq'::regclass);


--
-- Name: foro_mesa_participantes id_mesa_participante; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesa_participantes ALTER COLUMN id_mesa_participante SET DEFAULT nextval('public.foro_mesa_participantes_id_mesa_participante_seq'::regclass);


--
-- Name: foro_mesas id_mesa; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesas ALTER COLUMN id_mesa SET DEFAULT nextval('public.foro_mesas_id_mesa_seq'::regclass);


--
-- Name: foro_participantes id_participante; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_participantes ALTER COLUMN id_participante SET DEFAULT nextval('public.foro_participantes_id_participante_seq'::regclass);


--
-- Name: foro_temas id_tema; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_temas ALTER COLUMN id_tema SET DEFAULT nextval('public.foro_temas_id_tema_seq'::regclass);


--
-- Name: foros_eventos id_foro; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foros_eventos ALTER COLUMN id_foro SET DEFAULT nextval('public.foros_eventos_id_foro_seq'::regclass);


--
-- Name: guarderia_asistencia id_asistencia; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guarderia_asistencia ALTER COLUMN id_asistencia SET DEFAULT nextval('public.guarderia_asistencia_id_asistencia_seq'::regclass);


--
-- Name: guarderia_ninos id_nino; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guarderia_ninos ALTER COLUMN id_nino SET DEFAULT nextval('public.guarderia_ninos_id_nino_seq'::regclass);


--
-- Name: horarios_clases id_horario; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_clases ALTER COLUMN id_horario SET DEFAULT nextval('public.horarios_clases_id_horario_seq'::regclass);


--
-- Name: horarios_secciones id_horario; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_secciones ALTER COLUMN id_horario SET DEFAULT nextval('public.horarios_secciones_id_horario_seq'::regclass);


--
-- Name: inscripciones id_inscripcion; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inscripciones ALTER COLUMN id_inscripcion SET DEFAULT nextval('public.inscripciones_id_inscripcion_seq'::regclass);


--
-- Name: login_intentos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_intentos ALTER COLUMN id SET DEFAULT nextval('public.login_intentos_id_seq'::regclass);


--
-- Name: malla_materias id_materia; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.malla_materias ALTER COLUMN id_materia SET DEFAULT nextval('public.malla_materias_id_materia_seq'::regclass);


--
-- Name: mallas_curriculares id_malla; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mallas_curriculares ALTER COLUMN id_malla SET DEFAULT nextval('public.mallas_curriculares_id_malla_seq'::regclass);


--
-- Name: materia_contenidos id_contenido; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.materia_contenidos ALTER COLUMN id_contenido SET DEFAULT nextval('public.materia_contenidos_id_contenido_seq'::regclass);


--
-- Name: misiones_estudiantes id_estudiante_mision; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misiones_estudiantes ALTER COLUMN id_estudiante_mision SET DEFAULT nextval('public.misiones_estudiantes_id_estudiante_mision_seq'::regclass);


--
-- Name: notas_estudiantes id_nota; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notas_estudiantes ALTER COLUMN id_nota SET DEFAULT nextval('public.notas_estudiantes_id_nota_seq'::regclass);


--
-- Name: plan_evaluacion id_plan; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion ALTER COLUMN id_plan SET DEFAULT nextval('public.plan_evaluacion_id_plan_seq'::regclass);


--
-- Name: plan_evaluacion_contenidos id_plan_contenido; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion_contenidos ALTER COLUMN id_plan_contenido SET DEFAULT nextval('public.plan_evaluacion_contenidos_id_plan_contenido_seq'::regclass);


--
-- Name: prestamo_misiones id_prestamo; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prestamo_misiones ALTER COLUMN id_prestamo SET DEFAULT nextval('public.prestamo_misiones_id_prestamo_seq'::regclass);


--
-- Name: recepcion_alertas_qr id_alerta; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_alertas_qr ALTER COLUMN id_alerta SET DEFAULT nextval('public.recepcion_alertas_qr_id_alerta_seq'::regclass);


--
-- Name: recepcion_areas id_area; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_areas ALTER COLUMN id_area SET DEFAULT nextval('public.recepcion_areas_id_area_seq'::regclass);


--
-- Name: recepcion_visitantes id_visitante; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitantes ALTER COLUMN id_visitante SET DEFAULT nextval('public.recepcion_visitantes_id_visitante_seq'::regclass);


--
-- Name: recepcion_visitas id_visita; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitas ALTER COLUMN id_visita SET DEFAULT nextval('public.recepcion_visitas_id_visita_seq'::regclass);


--
-- Name: recuperacion_clave id_recuperacion; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recuperacion_clave ALTER COLUMN id_recuperacion SET DEFAULT nextval('public.recuperacion_clave_id_recuperacion_seq'::regclass);


--
-- Name: usuarios id_usuario; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios ALTER COLUMN id_usuario SET DEFAULT nextval('public.usuarios_id_usuario_seq'::regclass);


--
-- Data for Name: alimentos_tabla_venezuela; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.alimentos_tabla_venezuela (codigo_alimento, nombre_alimento, grupo_alimento, porcion_base_g, calorias_kcal, proteinas_g, grasas_totales_g, carbohidratos_g, fibra_dietetica_g, calcio_mg, fosforo_mg, hierro_mg, potasio_mg, sodio_mg, vitamina_c_mg) FROM stdin;
1	Arroz Blanco	Víveres	100.00	345.00	8.10	0.70	76.50	0.00	0.00	0.00	0.90	0.00	0.00	0.00
13	Avena en Hojuelas	Víveres	100.00	384.00	8.70	8.60	61.90	0.00	0.00	0.00	1.90	0.00	0.00	0.00
22	Harina de Maíz Amarillo	Víveres	100.00	355.00	9.20	1.20	82.00	0.00	0.00	0.00	1.00	0.00	0.00	0.00
25	Maizena (Fécula de Maíz)	Víveres	100.00	350.00	0.30	0.10	88.00	0.00	0.00	0.00	0.20	0.00	0.00	0.00
33	Harina de Maíz Blanco	Víveres	100.00	354.00	11.20	1.10	77.70	0.00	0.00	0.00	0.90	0.00	0.00	0.00
45	Harina de Trigo	Víveres	100.00	364.00	10.30	1.00	76.30	0.00	0.00	0.00	1.20	0.00	0.00	0.00
54	Pasta / Espagueti / Macarrones	Víveres	100.00	363.00	10.40	1.00	78.80	0.00	0.00	0.00	1.10	0.00	0.00	0.00
86	Pollo Entero / Beneficiado	Proteínas	100.00	183.00	20.20	10.20	0.00	0.00	0.00	0.00	1.60	0.00	0.00	0.00
105	Carne de Cerdo	Proteínas	100.00	242.00	17.00	19.00	0.00	0.00	0.00	0.00	1.20	0.00	0.00	0.00
106	Chuleta de Cerdo	Proteínas	100.00	250.00	18.30	18.40	0.00	0.00	0.00	0.00	1.70	0.00	0.00	0.00
107	Chuleta Ahumada	Proteínas	100.00	280.00	16.50	23.00	0.00	0.00	0.00	0.00	1.50	0.00	0.00	0.00
108	Costilla de Cerdo	Proteínas	100.00	320.00	15.00	28.00	0.00	0.00	0.00	0.00	1.10	0.00	0.00	0.00
109	Hueso Ahumado	Proteínas	100.00	210.00	14.00	17.00	0.00	0.00	0.00	0.00	1.00	0.00	0.00	0.00
112	Tocineta	Proteínas	100.00	541.00	9.00	55.00	1.00	0.00	0.00	0.00	0.80	0.00	0.00	0.00
120	Salchichas	Proteínas	100.00	260.00	12.00	23.00	1.50	0.00	0.00	0.00	1.20	0.00	0.00	0.00
122	Mortadela de Carne / Res	Proteínas	100.00	310.00	12.50	27.00	2.00	0.00	0.00	0.00	1.40	0.00	0.00	0.00
124	Mortadela de Pollo	Proteínas	100.00	240.00	13.00	19.00	2.00	0.00	0.00	0.00	1.10	0.00	0.00	0.00
150	Carne de Guisar (Res)	Proteínas	100.00	215.00	19.00	15.00	0.00	0.00	0.00	0.00	2.10	0.00	0.00	0.00
152	Carne de Mechar (Res)	Proteínas	100.00	205.00	20.00	13.00	0.00	0.00	0.00	0.00	2.30	0.00	0.00	0.00
154	Carne Molida (Res)	Proteínas	100.00	201.00	18.20	11.40	0.00	0.00	0.00	0.00	1.50	0.00	0.00	0.00
156	Costilla de Res	Proteínas	100.00	290.00	16.00	25.00	0.00	0.00	0.00	0.00	1.80	0.00	0.00	0.00
158	Hueso de Res	Proteínas	100.00	150.00	12.00	11.00	0.00	0.00	0.00	0.00	1.50	0.00	0.00	0.00
255	Huevos de Gallina	Proteínas	100.00	156.00	12.40	11.30	0.90	0.00	0.00	0.00	2.80	0.00	0.00	0.00
266	Leche Líquida	Líquidos	100.00	63.00	3.50	3.50	4.90	0.00	0.00	0.00	0.10	0.00	0.00	0.00
268	Leche en Polvo Completa	Líquidos	100.00	492.00	25.80	26.70	38.00	0.00	0.00	0.00	0.60	0.00	0.00	0.00
281	Queso Blanco Duro	Lácteos	100.00	389.00	24.90	31.60	1.40	0.00	0.00	0.00	0.80	0.00	0.00	0.00
285	Queso Amarillo	Lácteos	100.00	352.00	25.00	27.00	1.30	0.00	0.00	0.00	0.70	0.00	0.00	0.00
290	Mantequilla / Margarina	Lácteos	100.00	717.00	0.90	81.00	0.40	0.00	0.00	0.00	0.10	0.00	0.00	0.00
306	Arvejas	Víveres	100.00	271.00	23.20	1.30	64.30	0.00	0.00	0.00	3.90	0.00	0.00	0.00
313	Caraotas Negras	Víveres	100.00	248.00	23.00	1.20	66.70	0.00	0.00	0.00	2.10	0.00	0.00	0.00
320	Frijoles	Víveres	100.00	343.00	23.00	1.50	60.00	0.00	0.00	0.00	5.00	0.00	0.00	0.00
325	Quinchoncho	Víveres	100.00	340.00	21.00	1.40	62.00	0.00	0.00	0.00	4.50	0.00	0.00	0.00
329	Lentejas	Víveres	100.00	252.00	23.90	1.20	56.20	0.00	0.00	0.00	3.00	0.00	0.00	0.00
338	Apio (Raza / Tubérculo)	Perecederos	100.00	110.00	1.00	0.20	26.00	0.00	0.00	0.00	0.80	0.00	0.00	0.00
345	Ñame	Perecederos	100.00	118.00	1.50	0.20	27.50	0.00	0.00	0.00	0.50	0.00	0.00	0.00
346	Ocumo	Perecederos	100.00	112.00	1.80	0.20	26.00	0.00	0.00	0.00	0.70	0.00	0.00	0.00
348	Papa	Perecederos	100.00	81.00	2.00	0.10	18.90	0.00	0.00	0.00	0.60	0.00	0.00	0.00
351	Yuca	Perecederos	100.00	143.00	1.10	0.20	34.00	0.00	0.00	0.00	0.40	0.00	0.00	0.00
360	Ajo	Perecederos	100.00	149.00	6.40	0.50	33.00	0.00	0.00	0.00	1.70	0.00	0.00	0.00
362	Ahuyama (Calabaza)	Perecederos	100.00	26.00	1.00	0.10	6.50	0.00	0.00	0.00	0.80	0.00	0.00	0.00
364	Berenjena	Perecederos	100.00	25.00	1.00	0.20	5.80	0.00	0.00	0.00	0.40	0.00	0.00	0.00
368	Cebolla	Perecederos	100.00	43.00	1.40	0.20	9.70	0.00	0.00	0.00	0.80	0.00	0.00	0.00
369	Cebollín	Perecederos	100.00	32.00	1.80	0.40	7.30	0.00	0.00	0.00	1.20	0.00	0.00	0.00
370	Cilantro	Perecederos	100.00	23.00	2.10	0.50	3.70	0.00	0.00	0.00	1.80	0.00	0.00	0.00
371	España / Célere (Apio España)	Perecederos	100.00	16.00	0.70	0.20	3.00	0.00	0.00	0.00	0.50	0.00	0.00	0.00
385	Pimentón	Perecederos	100.00	20.00	0.90	0.20	4.60	0.00	0.00	0.00	0.50	0.00	0.00	0.00
386	Ají Dulce	Perecederos	100.00	28.00	1.20	0.30	6.00	0.00	0.00	0.00	0.60	0.00	0.00	0.00
387	Ají Picante	Perecederos	100.00	40.00	1.90	0.40	8.80	0.00	0.00	0.00	1.00	0.00	0.00	0.00
395	Tomate	Perecederos	100.00	15.00	1.40	0.20	3.10	0.00	0.00	0.00	0.90	0.00	0.00	0.00
400	Zanahoria	Perecederos	100.00	32.00	1.00	0.20	6.50	0.00	0.00	0.00	0.60	0.00	0.00	0.00
445	Cambur	Perecederos	100.00	89.00	1.10	0.30	22.80	0.00	0.00	0.00	0.30	0.00	0.00	0.00
450	Guayaba	Perecederos	100.00	68.00	2.60	1.00	14.30	0.00	0.00	0.00	0.30	0.00	0.00	0.00
452	Guanábana	Perecederos	100.00	66.00	1.00	0.30	16.80	0.00	0.00	0.00	0.60	0.00	0.00	0.00
458	Manzana	Perecederos	100.00	52.00	0.30	0.20	13.80	0.00	0.00	0.00	0.10	0.00	0.00	0.00
462	Pera	Perecederos	100.00	57.00	0.40	0.10	15.20	0.00	0.00	0.00	0.20	0.00	0.00	0.00
465	Piña	Perecederos	100.00	50.00	0.50	0.10	13.10	0.00	0.00	0.00	0.30	0.00	0.00	0.00
470	Patilla	Perecederos	100.00	30.00	0.60	0.20	7.60	0.00	0.00	0.00	0.20	0.00	0.00	0.00
476	Plátano Maduro / Verde	Perecederos	100.00	137.00	1.20	0.20	32.10	0.00	0.00	0.00	1.00	0.00	0.00	0.00
500	Azúcar	Víveres	100.00	387.00	0.00	0.00	99.90	0.00	0.00	0.00	0.10	0.00	0.00	0.00
510	Sal	Víveres	100.00	0.00	0.00	0.00	0.00	0.00	0.00	0.00	0.00	0.00	0.00	0.00
515	Café	Víveres	100.00	2.00	0.10	0.00	0.30	0.00	0.00	0.00	0.10	0.00	0.00	0.00
520	Salsa de Tomate	Víveres	100.00	100.00	1.20	0.30	24.00	0.00	0.00	0.00	0.50	0.00	0.00	0.00
525	Mayonesa	Víveres	100.00	680.00	1.00	75.00	1.00	0.00	0.00	0.00	0.30	0.00	0.00	0.00
536	Aceite Vegetal	Líquidos	100.00	900.00	0.00	100.00	0.00	0.00	0.00	0.00	0.00	0.00	0.00	0.00
\.


--
-- Data for Name: asignaciones_profesor; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.asignaciones_profesor (id_asignacion, id_profesor, id_materia, justificacion_asignacion, estatus_aceptacion, fecha_asignacion, fecha_respuesta, id_cohorte) FROM stdin;
12	1	52	Asignación de Cátedra	Aceptado	2026-11-18 18:22:32.888655	\N	5
18	1	58	Asignación de Cátedra	Aceptado	2026-11-19 15:00:05.876434	\N	10
19	1	55	Asignación de Cátedra	Aceptado	2026-11-19 15:00:21.12466	\N	6
\.


--
-- Data for Name: asistencia_clases; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.asistencia_clases (id_asistencia_clase, id_cohorte, id_estudiante, fecha_clase, hora_registro, estatus) FROM stdin;
\.


--
-- Data for Name: bitacora; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.bitacora (id_log, id_usuario, modulo, accion, ip_maquina, fecha_hora) FROM stdin;
1	1	SISTEMA	ACCESO_DASHBOARD - Ingreso al panel principal	::1	2026-10-14 13:09:22.22198
2	1	SISTEMA	ACCESO_DASHBOARD - Ingreso al panel principal	::1	2026-10-14 13:09:43.525128
3	1	SISTEMA	ACCESO_DASHBOARD - Ingreso al panel principal	::1	2026-10-14 13:10:27.535539
52	1	CONTROL DE ESTUDIO	DESCARGA FORMATO MATRICULA MASIVA (XLSX)	::1	2026-10-21 13:43:38.874931
53	1	CONTROL DE ESTUDIO	DESCARGA FORMATO MATRICULA MASIVA (XLSX) curso:COH-2026-NORA-01	::1	2026-10-21 13:45:56.819013
54	1	INFRAESTRUCTURA	DORMITORIO GUARDAR: Dormitorio A (30 camas)	::1	2026-10-21 23:29:46.852083
55	1	INFRAESTRUCTURA	PERNOCTA REGISTRAR: ISABEL CRISTINA MONSALVE MIJARES en Dormitorio A (entrada 2026-10-22 05:29, salida prevista 2026-10-23)	::1	2026-10-21 23:30:20.060157
56	1	INFRAESTRUCTURA	PERNOCTA FINALIZAR: ISABEL CRISTINA MONSALVE MIJARES en Dormitorio A	::1	2026-10-21 23:31:11.047853
24	1	INFRAESTRUCTURA	DORMITORIO GUARDAR: TMP DORM DBG (2 camas)	::1	2026-10-21 12:20:50.349935
29	1	INFRAESTRUCTURA	DORMITORIO GUARDAR: TMP DORM DBG2 (2 camas)	::1	2026-10-21 12:24:14.030843
57	1	SISTEMA	RESET_CLAVE_SUPERADMIN (revisión técnica autorizada)	local-cli	2026-11-18 16:22:26.381874
69	1	CONTROL DE ESTUDIO	DESCARGA FORMATO MATRICULA MASIVA (XLSX) curso:SEC-MCDMB1A	::1	2026-11-18 19:47:15.789551
70	1	RECEPCION	QR ALERTA USUARIO NO EXISTE: V-1 | KARL VAN KARL FRIECHICH | fuente:PADRON_NACIONAL -> Recepcion	::1	2026-11-18 20:14:15.934977
71	1	PROFESOR	PLAN_CREAR cohorte:5 materia:52 id_plan:4 peso:30 contenidos:2	::1	2026-11-18 23:15:09.19799
72	1	PROFESOR	PLAN_EDITAR cohorte:5 materia:52 id_plan:4 peso:35 contenidos:2	::1	2026-11-18 23:15:22.388123
73	1	PROFESOR	PLAN_CREAR cohorte:5 materia:52 id_plan:5 peso:5 contenidos:1	::1	2026-11-18 23:15:47.964911
74	1	PROFESOR	PLAN_CREAR cohorte:5 materia:52 id_plan:6 peso:5 contenidos:1	::1	2026-11-18 23:15:48.202934
75	1	PROFESOR	PLAN_ELIMINAR cohorte:5 materia:52 id_plan:1	::1	2026-11-18 23:20:24.428127
76	1	PROFESOR	PLAN_CREAR cohorte:5 materia:52 id_plan:7 peso:40 contenidos:4	::1	2026-11-18 23:28:31.92713
77	1	PROFESOR	PLAN_CREAR cohorte:5 materia:52 id_plan:8 peso:25 contenidos:2	::1	2026-11-18 23:31:11.063867
78	1	PROFESOR	PLAN_CREAR cohorte:5 materia:52 id_plan:9 peso:20 contenidos:1	::1	2026-11-18 23:32:48.755386
79	1	PROFESOR	PLAN_CREAR cohorte:5 materia:52 id_plan:10 peso:25 contenidos:1	::1	2026-11-18 23:33:22.139289
80	1	PROFESOR	PLAN_CREAR cohorte:5 materia:52 id_plan:11 peso:30 contenidos:4	::1	2026-11-18 23:34:08.074993
81	1	CONTROL DE ESTUDIO	CARGA MASIVA ESTUDIANTES curso:SEC-MCDMB1A fuente:archivo - creados:20 inscritos:0 omitidos:0	::1	2026-11-18 23:47:07.169793
82	1	PROFESOR	SABANA_GUARDAR cohorte:5 materia:52 filas:80	::1	2026-11-18 23:53:06.287004
83	1	INFRAESTRUCTURA	TORRE ELIMINAR: Dormitorio (id 8) - espacios:2 referencias_ajustadas:0	::1	2026-11-19 08:48:48.843453
84	1	PROFESOR	PLAN_CREAR cohorte:6 materia:55 id_plan:12 peso:25 contenidos:1	::1	2026-11-19 10:36:50.40134
85	1	PROFESOR	PLAN_CREAR cohorte:6 materia:55 id_plan:13 peso:25 contenidos:1	::1	2026-11-19 10:38:22.710221
86	1	PROFESOR	PLAN_CREAR cohorte:6 materia:55 id_plan:14 peso:20 contenidos:1	::1	2026-11-19 10:49:45.689963
87	1	PROFESOR	PLAN_CREAR cohorte:6 materia:55 id_plan:15 peso:30 contenidos:4	::1	2026-11-19 10:50:02.645867
88	1	PROFESOR	PLAN_EDITAR cohorte:6 materia:55 id_plan:15 peso:30 contenidos:4	::1	2026-11-19 10:50:41.114562
89	1	PROFESOR	SABANA_GUARDAR cohorte:5 materia:52 filas:80	::1	2026-11-19 10:57:24.564491
90	1	CONTROL DE ESTUDIO	DESCARGA FORMATO MATRICULA MASIVA (XLSX) curso:SEC-EV1A	::1	2026-11-19 11:24:17.977511
91	1	RECEPCION	VISITA REGISTRAR: V-20180793 -> Aula / Cohorte | fuente:PADRON_NACIONAL | motivo:Entrega de Almuerzo	::1	2026-11-19 12:36:08.094512
92	1	RECEPCION	VISITA SALIDA: id=3	::1	2026-11-19 12:36:25.529372
93	1	INSCRIPCION_CEDULA	CARGA_MASIVA_CEDULAS seccion=SEC-MCDMB1A procesadas=24 ok=24	::1	2026-11-19 16:32:58.502525
94	1	PROFESOR	SABANA_GUARDAR cohorte:5 materia:52 filas:96	::1	2026-11-19 16:42:02.169135
95	1	PERNOCTA	FINALIZAR EVENTO #7 fecha final 2026-11-20	::1	2026-11-19 22:52:56.72157
96	1	PERNOCTA	ABRIR INSCRIPCION EVENTO #7	::1	2026-11-19 22:53:05.419461
97	1	PERNOCTA	CERRAR INSCRIPCION EVENTO #7	::1	2026-11-19 22:53:55.249681
98	1	RECEPCION	PREREGISTRO PERNOCTA: V-20546388 -> cohorte #11 (usuario creado)	::1	2026-11-19 23:08:54.008907
99	1	RECEPCION	PREREGISTRO PERNOCTA: V-25884346 -> cohorte #11 (usuario creado)	::1	2026-11-19 23:09:25.060675
100	1	RECEPCION	PREREGISTRO PERNOCTA: V-25894741 -> cohorte #11 (usuario creado)	::1	2026-11-19 23:09:48.053567
101	1	RECEPCION	PREREGISTRO PERNOCTA: V-16896445 -> cohorte #11 (usuario creado)	::1	2026-11-19 23:12:15.547318
\.


--
-- Data for Name: campus_edificios; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.campus_edificios (id_edificio, nombre_torre, ubicacion_sede, total_pisos, descripcion, estatus, motivo_mantenimiento, inspector_responsable, trabajo_realizado, usuario_apertura) FROM stdin;
7	Modulo 3	Sede Principal	2	\N	Disponible	\N	\N	\N	\N
9	Dormitorio	Sede Principal	1	\N	Disponible	\N	\N	\N	\N
10	Modulo 1	Sede Principal	2	\N	Disponible	\N	\N	\N	\N
4	Modulo 2	Sede Principal	2	\N	Disponible	Fitracion	Kervyn Aguilar	Se coloco manto	Yaremi Escalona
11	Auditorio	Sede Principal	1	\N	Disponible	\N	\N	\N	\N
12	Talleres	Sede Principal	1	\N	Disponible	\N	\N	\N	\N
\.


--
-- Data for Name: campus_espacios; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.campus_espacios (id_espacio, id_edificio, tipo_espacio, nombre_espacio, piso_numero, capacidad_estudiantes, cantidad_dormitorios, camas_totales, enlace_virtual, estatus, motivo_mantenimiento, inspector_responsable, trabajo_realizado, usuario_apertura) FROM stdin;
11	4	Aula	Aula 1	1	20	0	0		Disponible	\N	\N	\N	\N
12	4	Aula	Aula 2	1	20	0	0		Disponible	\N	\N	\N	\N
16	7	Aula	Aula 9	1	20	0	0		Disponible	\N	\N	\N	\N
13	4	Aula	Aula 3	1	20	0	0		Disponible	\N	\N	\N	\N
19	9	Dormitorio/Residencia	dormitorio 1	1	18	0	0		Disponible	\N	\N	\N	\N
10	4	Aula	Informatica	1	12	0	0		Disponible	Reparacion del Aire	Kervyn Aguilar	Se coloco Gas	Yaremi Escalona
20	11	Auditorio	Auditorio María León	1	200	0	0		Disponible	\N	\N	\N	\N
21	12	Aula	Confección	1	25	0	0		Disponible	\N	\N	\N	\N
22	12	Aula	Peluqueria	1	15	0	0		Disponible	\N	\N	\N	\N
23	12	Aula	Masoterapia	1	25	0	0		Disponible	\N	\N	\N	\N
24	12	Aula	Manicura y Pericura	1	10	0	0		Disponible	\N	\N	\N	\N
\.


--
-- Data for Name: carreras_misiones; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.carreras_misiones (id_carrera, nombre_carrera, tipo_programa, duracion_meses) FROM stdin;
\.


--
-- Data for Name: certificado_configuracion; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.certificado_configuracion (id_config, plantilla_frente, plantilla_reverso, libro_numero, folio_inicial, linea_inicial, folio_actual, linea_actual, id_autoridad, fecha_actualizacion) FROM stdin;
1	assets/img/plantilla_frente.jpg	assets/img/plantilla_reverso.jpg	Libro 1	1	1	4	4	\N	2026-11-20 00:13:55.581848
\.


--
-- Data for Name: certificado_envios; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.certificado_envios (id_envio, id_certificado, correo, estatus, mensaje, fecha_envio) FROM stdin;
\.


--
-- Data for Name: certificados_emitidos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.certificados_emitidos (id_certificado, id_inscripcion, id_estudiante, id_malla, tipo, folio, fecha_emision, id_usuario_emisor, estado, libro, linea_libro, codigo_validacion, origen, id_foro) FROM stdin;
\.


--
-- Data for Name: cohortes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cohortes (id_cohorte, codigo_cohorte, id_malla, id_profesor_guia, salon_aula, dias_clase, horas_diarias, fecha_inicio, fecha_fin_estimada, cupos_maximos, estatus, num_periodo, hora_inicio, hora_fin, inscripcion_pernocta_abierta) FROM stdin;
5	SEC-MCDMB1A	11	2	Aula 1	Lunes a Viernes	4	2026-10-01	2027-05-18	30	Planificada	1	08:00:00	12:30:00	f
6	SEC-CNB1A	13	\N	Aula 2	Lunes a Viernes	4	2026-11-19	2027-02-19	20	Planificada	1	08:00:00	12:30:00	f
10	SEC-CNI1A	15	\N	Aula 9	Sábado y Domingo	4	2026-11-19	2027-05-19	30	Planificada	1	08:00:00	12:30:00	f
7	SEC-EV1A	14	2	Auditorio María León, Aula 3	Lunes a Viernes	4	2026-11-19	2026-11-20	30	En Curso	1	08:00:00	17:30:00	f
11	SEC-EV1B	14	\N	Auditorio María León	Lunes a Viernes	4	2026-11-25	2026-11-26	30	Planificada	1	08:00:00	12:30:00	t
\.


--
-- Data for Name: comedor_asistencia; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.comedor_asistencia (id_asistencia, id_usuario, fecha_registro, hora_registro, turno) FROM stdin;
12	1	2026-11-18	20:18:36.067881	Almuerzo
13	1	2026-11-19	20:18:34	Almuerzo
\.


--
-- Data for Name: comedor_inventario; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.comedor_inventario (id_producto, nombre_producto, categoria, existencia_actual, unidad_medida, nivel_minimo, fecha_actualizacion, codigo_alimento_inn) FROM stdin;
1	Huevos	Proteínas	25.00	Unidades	5.00	2026-10-15 00:20:54.871891	\N
2	Leche Líquida	Líquidos	2.00	Litros	5.00	2026-10-15 00:21:15.122893	\N
4	tomate	Perecederos	25.00	Kg	5.00	2026-10-15 00:22:22.172048	\N
3	Hortalizas / Verduras	Perecederos	8.98	Kg	5.00	2026-10-15 10:55:07.257902	\N
6	Harina de Maíz Blanco	Víveres	40.00	Kg	5.00	2026-10-15 13:50:14.485403	33
7	Queso Blanco Duro	Lácteos	50.00	Kg	5.00	2026-11-19 16:49:49.520768	281
\.


--
-- Data for Name: comedor_menu; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.comedor_menu (id_menu, fecha, turno_comida, descripcion_plato, estatus) FROM stdin;
\.


--
-- Data for Name: comedor_movimientos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.comedor_movimientos (id_movimiento, id_producto, tipo_movimiento, cantidad, motivo_observacion, id_usuario_registro, fecha_movimiento) FROM stdin;
1	1	Entrada	25.00	jjjj	1	2026-10-15 00:20:54.871891
2	2	Entrada	2.00		1	2026-10-15 00:21:15.122893
3	3	Entrada	9.98		1	2026-10-15 00:21:54.14423
4	4	Entrada	25.00		1	2026-10-15 00:22:22.172048
5	3	Salida	1.00		1	2026-10-15 10:55:07.257902
6	6	Entrada	40.00		1	2026-10-15 13:50:14.485403
7	7	Entrada	100.00		1	2026-11-19 16:49:16.80407
8	7	Salida	50.00	Almuerzo	1	2026-11-19 16:49:49.520768
\.


--
-- Data for Name: configuracion_autoridades; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.configuracion_autoridades (id_autoridad, cargo, nombre_apellido, cedula, tipo_nombramiento, numero_nombramiento, fecha_gaceta_nombramiento, firma_digital, fecha_actualizacion, estatus, fecha_inicio_gestion, fecha_fin_gestion) FROM stdin;
1	Directora de la Escuela	Dra. Nora Castañeda	12345678	Providencia Administrativa	PA-2026-001	2026-01-15	assets/img/firmas/firma_directora.png	2026-10-19 14:37:05.310543	Activo	2026-10-19	\N
2	Director de Control de Estudio	Lcdo. Control de Estudio	18765432	Providencia Administrativa	PA-2026-002	2026-01-15	assets/img/firmas/firma_control_estudio.png	2026-10-19 14:37:05.310543	Activo	2026-10-19	\N
\.


--
-- Data for Name: cursos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cursos (id_curso, nombre_curso, id_profesor, plan_evaluacion, horas_academicas, cupos_maximos, fecha_inicio, fecha_fin, estatus_curso, fecha_creacion) FROM stdin;
\.


--
-- Data for Name: defensoria_casos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.defensoria_casos (id_caso, codigo_expediente, cedula_victimaria_afectada, nombre_victima, telefono_victima, direccion_victima, tipo_violencia, descripcion_hechos, estatus_caso, id_defensora, fecha_registro) FROM stdin;
1	EXP-2026-2020	8845406	ISABEL CRISTINA MONSALVE MIJARES	04123918270	ddddd	Violencia Física	ddddddddd	En Proceso	1	2026-10-14 22:54:18.260722
\.


--
-- Data for Name: defensoria_seguimiento; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.defensoria_seguimiento (id_seguimiento, id_caso, observacion, acuerdos_compromisos, id_responsable, fecha_atencion) FROM stdin;
1	1	ddddddd	ddddddd	1	2026-10-14 22:54:41.149532
2	1	ddddd	dddfg	1	2026-10-14 22:55:18.725045
\.


--
-- Data for Name: discapacidad; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.discapacidad (id, nombre, activo, created_at) FROM stdin;
1	Ninguna	1	2026-10-13 23:38:15.536704
2	Visual	1	2026-10-13 23:38:15.536704
3	Auditiva	1	2026-10-13 23:38:15.536704
4	Motora / Físico-Motora	1	2026-10-13 23:38:15.536704
5	Intelectual	1	2026-10-13 23:38:15.536704
6	Psicosocial	1	2026-10-13 23:38:15.536704
7	Múltiple	1	2026-10-13 23:38:15.536704
\.


--
-- Data for Name: dormitorio_lista_espera; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.dormitorio_lista_espera (id_lista_espera, id_cohorte, id_usuario, cedula, nombre_completo, telefono, fecha_solicitud, estatus, id_pernocta_asignada, id_usuario_registro, observaciones, id_foro) FROM stdin;
\.


--
-- Data for Name: dormitorio_mudanzas; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.dormitorio_mudanzas (id_mudanza, id_pernocta, id_dormitorio_origen, id_dormitorio_destino, fecha_mudanza, motivo, id_usuario_registro) FROM stdin;
\.


--
-- Data for Name: dormitorio_pernoctas; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.dormitorio_pernoctas (id_pernocta, id_dormitorio, id_usuario, es_visitante_foraneo, nombre_completo, cedula, telefono, id_cohorte, fecha_entrada, hora_entrada, fecha_salida_prevista, fecha_salida_real, estado, motivo, observaciones, id_usuario_registro, fecha_registro, id_foro) FROM stdin;
\.


--
-- Data for Name: dormitorio_prorrogas; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.dormitorio_prorrogas (id_prorroga, id_pernocta, dias_concedidos, fecha_salida_anterior, fecha_salida_nueva, motivo, id_usuario, fecha_registro) FROM stdin;
\.


--
-- Data for Name: dormitorio_responsables; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.dormitorio_responsables (id_responsable_registro, id_dormitorio, id_usuario, fecha_desde, fecha_hasta, motivo_cambio, id_usuario_registro) FROM stdin;
\.


--
-- Data for Name: dormitorios; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.dormitorios (id_dormitorio, nombre, descripcion, capacidad_camas, id_responsable, telefono_responsable, estatus, fecha_registro, fecha_ultima_responsable, planta) FROM stdin;
1	Dormitorio A	sfwe	30	1	04123918270	Disponible	2026-10-21 23:29:46.780561	\N	\N
\.


--
-- Data for Name: etnia; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.etnia (id, nombre, activo, created_at) FROM stdin;
1	Wayuu	1	2026-10-13 23:38:15.536704
2	Warao	1	2026-10-13 23:38:15.536704
3	Kariña	1	2026-10-13 23:38:15.536704
4	Pemón	1	2026-10-13 23:38:15.536704
5	Anu	1	2026-10-13 23:38:15.536704
6	Yukpa	1	2026-10-13 23:38:15.536704
7	Bari	1	2026-10-13 23:38:15.536704
8	Piaroa	1	2026-10-13 23:38:15.536704
9	Ninguna / No pertenece	1	2026-10-13 23:38:15.536704
\.


--
-- Data for Name: feriados_venezuela; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.feriados_venezuela (id_feriado, fecha, descripcion) FROM stdin;
1	2026-01-01	Año Nuevo
2	2026-04-19	Declaración de la Independencia
3	2026-05-01	Día del Trabajador
4	2026-06-24	Batalla de Carabobo
5	2026-07-05	Día de la Independencia
6	2026-07-24	Natalicio del Libertador
7	2026-10-12	Día de la Resistencia Indígena
8	2026-12-24	Víspera de Navidad
9	2026-12-25	Navidad
10	2026-12-31	Fin de Año
\.


--
-- Data for Name: foro_asistencias; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foro_asistencias (id_asistencia, id_participante, tipo_asistencia, fecha_registro, registrado_por, observaciones) FROM stdin;
\.


--
-- Data for Name: foro_certificados; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foro_certificados (id_certificado_foro, id_participante, rol_certificado, id_certificado_emitido, fecha_emision, id_usuario_emisor) FROM stdin;
\.


--
-- Data for Name: foro_informes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foro_informes (id_informe, id_mesa, id_participante, contenido, estatus, fecha_envio, fecha_actualizacion) FROM stdin;
\.


--
-- Data for Name: foro_mesa_participantes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foro_mesa_participantes (id_mesa_participante, id_mesa, id_participante, fecha_registro) FROM stdin;
\.


--
-- Data for Name: foro_mesas; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foro_mesas (id_mesa, id_foro, numero_mesa, nombre_mesa, id_transcriptor, capacidad, estatus, informe_transcriptor, fecha_informe_transcriptor, fecha_registro) FROM stdin;
\.


--
-- Data for Name: foro_participantes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foro_participantes (id_participante, id_foro, id_estudiante, estatus, fecha_registro, id_usuario_registro, observaciones) FROM stdin;
\.


--
-- Data for Name: foro_temas; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foro_temas (id_tema, id_foro, nombre_tema, ponente, horas_academicas, contenido, orden_tema, fecha_registro) FROM stdin;
\.


--
-- Data for Name: foros_eventos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.foros_eventos (id_foro, codigo_foro, nombre, tipo_foro, descripcion, fecha_inicio, fecha_fin, lugar, horas_academicas, id_malla, estatus, id_usuario_creador, fecha_creacion, pernocta_habilitada) FROM stdin;
\.


--
-- Data for Name: guarderia_asistencia; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.guarderia_asistencia (id_asistencia, id_nino, fecha_asistencia, hora_entrada, hora_salida, estatus) FROM stdin;
1	1	2026-10-14	23:33:08.345711	23:33:11.96351	Retirado
\.


--
-- Data for Name: guarderia_ninos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.guarderia_ninos (id_nino, id_representante, nombre_nino, apellido_nino, fecha_nacimiento, sexo, observaciones, fecha_registro) FROM stdin;
1	1	Kenner Daniel	Aguilar Peña	2020-10-20	Masculino		2026-10-14 23:32:58.247361
\.


--
-- Data for Name: horarios_clases; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.horarios_clases (id_horario, id_cohorte, id_materia, nombre_materia, dia_semana, hora_inicio, hora_fin, orden, fecha_generacion) FROM stdin;
\.


--
-- Data for Name: horarios_secciones; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.horarios_secciones (id_horario, id_cohorte, id_materia, id_profesor, dia_semana, hora_inicio, hora_fin, salon_aula, created_at) FROM stdin;
235	10	58	1	Sábado	08:00:00	08:45:00	Aula 9	2026-11-19 15:14:09.161748
236	10	58	1	Sábado	08:45:00	09:30:00	Aula 9	2026-11-19 15:14:09.161748
237	10	58	1	Sábado	09:45:00	10:30:00	Aula 9	2026-11-19 15:14:09.161748
238	10	58	1	Sábado	10:30:00	11:15:00	Aula 9	2026-11-19 15:14:09.161748
239	10	58	1	Sábado	11:15:00	12:00:00	Aula 9	2026-11-19 15:14:09.161748
46	5	52	1	Lunes	07:00:00	07:45:00	Aula 1	2026-11-18 18:22:53.02685
47	5	52	1	Lunes	08:00:00	08:45:00	Aula 1	2026-11-18 18:22:53.02685
48	5	52	1	Lunes	08:45:00	09:30:00	Aula 1	2026-11-18 18:22:53.02685
49	5	52	1	Lunes	09:30:00	09:45:00	Aula 1	2026-11-18 18:22:53.02685
50	5	52	1	Jueves	07:00:00	07:45:00	Aula 1	2026-11-18 18:22:53.02685
51	5	52	1	Jueves	08:00:00	08:45:00	Aula 1	2026-11-18 18:22:53.02685
52	5	52	1	Jueves	08:45:00	09:30:00	Aula 1	2026-11-18 18:22:53.02685
53	5	52	1	Jueves	09:30:00	09:45:00	Aula 1	2026-11-18 18:22:53.02685
56	6	55	1	Lunes	09:45:00	10:30:00	Aula 2	2026-11-19 10:15:34.726964
57	6	55	1	Lunes	10:30:00	11:15:00	Aula 2	2026-11-19 10:15:34.726964
58	6	55	1	Lunes	11:15:00	12:00:00	Aula 2	2026-11-19 10:15:34.726964
59	6	55	1	Lunes	12:00:00	13:15:00	Aula 2	2026-11-19 10:15:34.726964
60	6	55	1	Martes	08:00:00	08:45:00	Aula 2	2026-11-19 10:15:34.726964
61	6	55	1	Martes	08:45:00	09:30:00	Aula 2	2026-11-19 10:15:34.726964
62	6	55	1	Martes	09:30:00	09:45:00	Aula 2	2026-11-19 10:15:34.726964
63	6	55	1	Martes	09:45:00	10:30:00	Aula 2	2026-11-19 10:15:34.726964
\.


--
-- Data for Name: inscripciones; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.inscripciones (id_inscripcion, id_curso, id_estudiante, fecha_inscripcion, nota_cualitativa, folio_certificado, id_etnia, id_discapacidad) FROM stdin;
3	5	44	2026-11-19 16:32:56.498367	En Progreso	\N	\N	\N
4	5	45	2026-11-19 16:32:56.588472	En Progreso	\N	\N	\N
5	5	46	2026-11-19 16:32:56.676211	En Progreso	\N	\N	\N
6	5	47	2026-11-19 16:32:56.761669	En Progreso	\N	\N	\N
7	5	48	2026-11-19 16:32:56.846751	En Progreso	\N	\N	\N
8	5	49	2026-11-19 16:32:56.932281	En Progreso	\N	\N	\N
9	5	50	2026-11-19 16:32:57.018414	En Progreso	\N	\N	\N
10	5	51	2026-11-19 16:32:57.104592	En Progreso	\N	\N	\N
11	5	52	2026-11-19 16:32:57.189744	En Progreso	\N	\N	\N
12	5	53	2026-11-19 16:32:57.276808	En Progreso	\N	\N	\N
13	5	54	2026-11-19 16:32:57.364693	En Progreso	\N	\N	\N
14	5	55	2026-11-19 16:32:57.450132	En Progreso	\N	\N	\N
15	5	56	2026-11-19 16:32:57.535656	En Progreso	\N	\N	\N
16	5	57	2026-11-19 16:32:57.623255	En Progreso	\N	\N	\N
17	5	58	2026-11-19 16:32:57.71582	En Progreso	\N	\N	\N
18	5	59	2026-11-19 16:32:57.803506	En Progreso	\N	\N	\N
19	5	60	2026-11-19 16:32:57.889932	En Progreso	\N	\N	\N
20	5	61	2026-11-19 16:32:57.977501	En Progreso	\N	\N	\N
21	5	62	2026-11-19 16:32:58.06614	En Progreso	\N	\N	\N
22	5	63	2026-11-19 16:32:58.152996	En Progreso	\N	\N	\N
23	5	64	2026-11-19 16:32:58.240346	En Progreso	\N	\N	\N
24	5	65	2026-11-19 16:32:58.326448	En Progreso	\N	\N	\N
25	5	66	2026-11-19 16:32:58.412078	En Progreso	\N	\N	\N
26	5	67	2026-11-19 16:32:58.499895	En Progreso	\N	\N	\N
27	11	68	2026-11-19 23:08:54.004936	En Progreso	\N	\N	\N
28	11	69	2026-11-19 23:09:25.056869	En Progreso	\N	\N	\N
29	11	70	2026-11-19 23:09:48.05057	En Progreso	\N	\N	\N
30	11	71	2026-11-19 23:12:15.543824	En Progreso	\N	\N	\N
\.


--
-- Data for Name: inscripciones_respaldo_20261119; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.inscripciones_respaldo_20261119 (id_inscripcion, id_curso, id_estudiante, fecha_inscripcion, nota_cualitativa, folio_certificado, id_etnia, id_discapacidad) FROM stdin;
3	5	22	2026-11-18 23:47:05.517971	En Progreso	\N	\N	\N
4	5	23	2026-11-18 23:47:05.607177	En Progreso	\N	\N	\N
5	5	24	2026-11-18 23:47:05.694311	En Progreso	\N	\N	\N
6	5	25	2026-11-18 23:47:05.780976	En Progreso	\N	\N	\N
7	5	26	2026-11-18 23:47:05.87017	En Progreso	\N	\N	\N
8	5	27	2026-11-18 23:47:05.956079	En Progreso	\N	\N	\N
9	5	28	2026-11-18 23:47:06.041891	En Progreso	\N	\N	\N
10	5	29	2026-11-18 23:47:06.129533	En Progreso	\N	\N	\N
11	5	30	2026-11-18 23:47:06.218152	En Progreso	\N	\N	\N
12	5	31	2026-11-18 23:47:06.305086	En Progreso	\N	\N	\N
13	5	32	2026-11-18 23:47:06.39056	En Progreso	\N	\N	\N
14	5	33	2026-11-18 23:47:06.476189	En Progreso	\N	\N	\N
15	5	34	2026-11-18 23:47:06.561184	En Progreso	\N	\N	\N
16	5	35	2026-11-18 23:47:06.64925	En Progreso	\N	\N	\N
17	5	36	2026-11-18 23:47:06.734337	En Progreso	\N	\N	\N
18	5	37	2026-11-18 23:47:06.819395	En Progreso	\N	\N	\N
19	5	38	2026-11-18 23:47:06.90514	En Progreso	\N	\N	\N
20	5	39	2026-11-18 23:47:06.994282	En Progreso	\N	\N	\N
21	5	40	2026-11-18 23:47:07.082416	En Progreso	\N	\N	\N
22	5	41	2026-11-18 23:47:07.168911	En Progreso	\N	\N	\N
\.


--
-- Data for Name: login_intentos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.login_intentos (id, ip, cedula, exito, fecha) FROM stdin;
76	::1	17494175	t	2026-11-18 16:23:12.013391-04
77	::1	17494175	t	2026-11-18 16:24:24.990074-04
78	::1	17494175	t	2026-11-18 16:34:06.104008-04
80	::1	17494175	t	2026-11-18 18:22:06.280219-04
83	::1	17494175	t	2026-11-18 19:44:50.651398-04
84	::1	17494175	t	2026-11-18 20:00:42.62363-04
85	192.168.10.104	17494175	t	2026-11-18 20:04:05.91807-04
86	::1	17494175	t	2026-11-18 20:11:17.193695-04
87	::1	17494175	t	2026-11-18 20:21:38.151756-04
88	192.168.11.155	17494175	t	2026-11-18 20:27:17.437125-04
89	::1	17494175	t	2026-11-18 20:31:25.730771-04
90	192.168.10.104	17494175	t	2026-11-18 20:32:59.048976-04
91	::1	17494175	t	2026-11-18 21:09:32.956605-04
92	::1	17494175	t	2026-11-18 21:09:59.612467-04
93	::1	17494175	t	2026-11-18 21:10:11.128804-04
94	::1	17494175	t	2026-11-18 21:15:25.477548-04
95	::1	17494175	t	2026-11-18 21:27:51.246306-04
96	192.168.10.104	17494175	t	2026-11-18 21:29:41.262055-04
97	::1	17494175	t	2026-11-18 21:32:40.821477-04
98	192.168.10.104	17494175	t	2026-11-18 21:35:11.249098-04
99	192.168.10.104	17494175	t	2026-11-18 22:33:27.084055-04
100	192.168.10.104	17494175	t	2026-11-18 22:39:37.254456-04
101	::1	17494175	t	2026-11-18 23:19:48.102435-04
102	::1	17494175	t	2026-11-19 08:48:06.964642-04
103	::1	17494175	t	2026-11-19 09:39:31.288641-04
104	::1	17494175	t	2026-11-19 11:28:41.598958-04
105	::1	17494175	t	2026-11-19 22:49:05.177493-04
107	::1	17494175	t	2026-11-20 00:03:19.462168-04
108	::1	20000001	t	2026-11-20 00:11:18.593932-04
109	::1	20000002	t	2026-11-20 00:12:17.092022-04
110	::1	17494175	t	2026-11-20 00:13:07.223844-04
111	::1	20000003	t	2026-11-20 00:15:47.960012-04
112	::1	17494175	t	2026-11-20 00:16:07.907029-04
113	::1	8845406	t	2026-11-20 00:31:15.838187-04
114	::1	V-17494175	f	2026-11-20 00:59:55.325335-04
115	::1	17494175	t	2026-11-20 01:01:14.433161-04
\.


--
-- Data for Name: malla_materias; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.malla_materias (id_materia, id_malla, modulo_num, nombre_materia, contenido_detallado, horas_academicas) FROM stdin;
52	11	1	Modulo I	• Sensibilización, Historia de la Vestimenta y Alfabetización Visual. (30 hrs)\n• Tecnología Textil y Mecánica de Costura. (30 hrs)\n• Antropometría, Ergonomía y el Cuerpo como Territorio. (30 hrs)\n• Teoría, Geometría y Simbología del Patronaje Plano. (30 hrs)	120
53	12	1	Modulo II	• Geometría Aplicada al Trazado de Moldes Básicos. (30 hrs)\n• Corte Estratégico y Optimización con Residuo Cero. (30 hrs)\n• Ensamble Técnico e Ingeniería del Mantenimiento. (30 hrs)\n• Estructura de Costos Reales y Economía Feminista. (30 hrs)	120
55	13	1	Módulo I	• Geopolítica del Cacao (30 hrs)\n• El Grano y su Anatomía (30 hrs)\n• Tueste y Descascarillado (30 hrs)\n• Molienda y Refinado (30 hrs)	120
56	14	1	Historia	• Tema 1 (8 hrs)\n• Tema 2 (8 hrs)	16
57	14	2	Temas Varios	• Contenido 1 (8 hrs)\n• Contenido 2 (8 hrs)	16
58	15	1	Módulo II	• Física del Chocolate (30 hrs)\n• Métodos de Templado (30 hrs)\n• Coberturas Artesanales (30 hrs)\n• Defectos del Chocolate (30 hrs)	120
\.


--
-- Data for Name: mallas_curriculares; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.mallas_curriculares (id_malla, nombre_programa, tipo_oferta, requiere_pernocta, duracion_dias_estimada, descripcion, fecha_creacion, tipo_regimen, total_periodos) FROM stdin;
11	Modulo I Confección y diseño de modas Basico	Carrera Larga	f	0		2026-11-18 17:41:01.557271	Módulo	1
12	Corte y Confección y Diseño de Moda Nivel Intermedio	Carrera Larga	f	0		2026-11-18 17:45:09.877571	Módulo	1
13	Chocolatería Nivel Básico	Carrera Larga	f	0		2026-11-19 09:59:10.975209	Módulo	1
14	Erradicación de la Violencia	Seminarios	t	0		2026-11-19 11:09:17.406665	Módulo	2
15	Chocolatería Nivel Intermedio	Carrera Larga	f	0		2026-11-19 14:38:27.610247	Módulo	1
\.


--
-- Data for Name: materia_contenidos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.materia_contenidos (id_contenido, id_materia, descripcion, horas_academicas, orden) FROM stdin;
1	52	Sensibilización, Historia de la Vestimenta y Alfabetización Visual.	30	1
2	52	Tecnología Textil y Mecánica de Costura.	30	2
3	52	Antropometría, Ergonomía y el Cuerpo como Territorio.	30	3
4	52	Teoría, Geometría y Simbología del Patronaje Plano.	30	4
5	53	Geometría Aplicada al Trazado de Moldes Básicos.	30	1
6	53	Corte Estratégico y Optimización con Residuo Cero.	30	2
7	53	Ensamble Técnico e Ingeniería del Mantenimiento.	30	3
8	53	Estructura de Costos Reales y Economía Feminista.	30	4
9	55	Geopolítica del Cacao	30	1
10	55	El Grano y su Anatomía	30	2
11	55	Tueste y Descascarillado	30	3
12	55	Molienda y Refinado	30	4
\.


--
-- Data for Name: misiones_estudiantes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.misiones_estudiantes (id_estudiante_mision, id_prestamo, cedula, nombre, apellido, fecha_nacimiento, edad, estado_civil, telefono, id_etnia, id_discapacidad, fecha_registro) FROM stdin;
1	2	15837853	MAICKEL JOSE	MORA SANCHEZ	2005-10-10	21	Soltero(a)	04168982547	\N	\N	2026-10-20 14:45:22.846735
\.


--
-- Data for Name: monitor_sincronizaciones; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.monitor_sincronizaciones (uuid_cliente, id_usuario, modulo, accion, fecha_cliente, fecha_servidor, resultado) FROM stdin;
\.


--
-- Data for Name: notas_estudiantes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.notas_estudiantes (id_nota, id_plan, id_estudiante, nota_cuantitativa, nota_cualitativa, nota_recuperacion, observaciones) FROM stdin;
3	8	33	10.00	Aprobado	\N	\N
4	9	33	15.00	Aprobado	\N	\N
5	10	33	10.00	Aprobado	\N	\N
6	11	33	17.00	Aprobado	\N	\N
11	8	26	10.00	Aprobado	\N	\N
12	9	26	13.00	Reprobado	5.00	\N
13	10	26	15.00	Aprobado	13.00	\N
14	11	26	11.00	Aprobado	\N	\N
19	8	66	16.00	Aprobado	\N	\N
20	9	66	18.00	Aprobado	\N	\N
21	10	66	19.00	Aprobado	\N	\N
22	11	66	20.00	Aprobado	\N	\N
\.


--
-- Data for Name: plan_evaluacion; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.plan_evaluacion (id_plan, id_cohorte, id_materia, titulo_evaluacion, tipo_ponderacion, porcentaje_peso, es_asistencia, fecha_evaluacion, id_profesor, descripcion_libre, instrumento_evaluacion) FROM stdin;
8	5	52	Evaluacion 1	Cuantitativa	25.00	f	2026-11-19	1		Prueba escrita
9	5	52	Evaluacion 2	Cuantitativa	20.00	f	2026-11-19	1	una prueba 1 2 3 4 5 6 7 8 9 10	Trabajo práctico
10	5	52	Evaluacion 3	Cuantitativa	25.00	f	2026-11-19	1		Trabajo práctico
11	5	52	Prueba Final	Cuantitativa	30.00	f	2026-11-19	1		Proyecto / investigación
12	6	55	Evaluacion 1	Cuantitativa	25.00	f	2026-11-26	1		Trabajo práctico
13	6	55	Evaluacion 2	Cuantitativa	25.00	f	2026-11-30	1	gggffdeddfd	Exposición oral
14	6	55	Evaluacion 3	Cuantitativa	20.00	f	2026-11-19	1		Exposición oral
15	6	55	Prueba Final	Cuantitativa	30.00	f	2027-01-05	1		Prueba escrita
\.


--
-- Data for Name: plan_evaluacion_contenidos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.plan_evaluacion_contenidos (id_plan_contenido, id_plan, id_contenido) FROM stdin;
11	8	1
12	8	2
13	9	2
14	10	3
15	11	1
16	11	2
17	11	3
18	11	4
19	12	9
20	13	10
21	14	11
26	15	9
27	15	10
28	15	11
29	15	12
\.


--
-- Data for Name: prestamo_misiones; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.prestamo_misiones (id_prestamo, nombre_mision, facilitador_responsable, cedula_facilitador, telefono_facilitador, cantidad_estudiantes, periodo_academico, salon_asignado, dias_horario, fecha_inicio, fecha_fin, observaciones, fecha_nacimiento_facilitador, edad_facilitador, estado_civil_facilitador) FROM stdin;
2	Otra: Simón Rodríguez (Licenciatura de Administración)	OSWALDO RAMON QUERALES	14563214	04244415689	20	Trayecto 2026	Torre a - eeeeeeee (Piso 1)	Sábado,Domingo 08:00 a 12:00	2026-10-20	2027-04-20	Prestamo 1	1995-11-15	30	Soltero(a)
\.


--
-- Data for Name: recepcion_alertas_qr; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.recepcion_alertas_qr (id_alerta, cedula, id_usuario, fecha_lectura, hora_lectura, tipo_alerta, area_destino, atendida, id_usuario_atencion, fecha_atencion, observaciones, nombre_completo) FROM stdin;
5	1	\N	2026-11-18	20:14:15.92926	USUARIO_NO_EXISTE	Recepción	f	\N	\N	\N	KARL VAN KARL FRIECHICH
\.


--
-- Data for Name: recepcion_areas; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.recepcion_areas (id_area, nombre, descripcion, activo) FROM stdin;
1	Control de Estudio	Matrícula, inscripción y asignación de cohorte	t
2	Dirección	Dirección de la escuela	t
3	Comedor	Atención de comensales	t
4	Guardería	Área de guardería	t
5	Defensoría	Defensoría del estudiante	t
6	Dormitorios	Pernoctas y residencias	t
7	Aula / Cohorte	Clase programada del estudiante	t
8	Seguridad	Seguridad perimetral	t
9	Otro	Otro destino indicado por recepción	t
\.


--
-- Data for Name: recepcion_visitantes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.recepcion_visitantes (id_visitante, es_usuario_sistema, id_usuario, cedula, nombre_completo, telefono, entidad_origen, creado_en, fuente_datos, fecha_nacimiento, genero) FROM stdin;
3	f	\N	20180793	YUSLIANY ISABEL RINCONES HERNANDEZ	\N	\N	2026-11-19 12:36:08.057652	PADRON_NACIONAL	1990-12-23	Femenino
\.


--
-- Data for Name: recepcion_visitas; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.recepcion_visitas (id_visita, id_visitante, id_area_destino, id_cohorte, motivo, fecha_entrada, hora_entrada, fecha_salida, hora_salida, estatus, id_usuario_registro, observaciones, alerta_sin_curso, destino_recomendado, fecha_registro) FROM stdin;
3	3	7	6	Entrega de Almuerzo	2026-11-19	12:36:08.057652	2026-11-19	12:36:25.523106	Finalizada	1	\N	f	\N	2026-11-19 12:36:08.057652
\.


--
-- Data for Name: recuperacion_clave; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.recuperacion_clave (id_recuperacion, id_usuario, codigo_verificacion, fecha_expiracion, usado) FROM stdin;
\.


--
-- Data for Name: usuarios; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.usuarios (id_usuario, cedula, nombre, apellido, email, password, telefono, foto, tipo_usuario, estatus_cuenta, id_municipio, fecha_registro, fecha_nacimiento, sexo, edad, estado_civil, foto_perfil, cargo_funcion, roles_asignados, etnia, tipo_discapacidad, estado, municipio, parroquia, direccion_completa, inscrito_cne, cne_estado, cne_municipio, cne_parroquia, cne_centro_electoral, tiene_hijos, cantidad_hijos, correo, reset_token, reset_expira, horas_semanales_min, horas_semanales_max, disponibilidad_horaria, espacios_permitidos) FROM stdin;
19	30666777	MONITOR	DEMO	\N	$2y$10$H9KhsJXNjcBGQXul.a3noOQPfphRgXl2/RD/1XuacFBQT7AhjNAOq	\N	assets/img/user-default.png	monitor	Activo	\N	2026-10-21 12:55:13.361026	\N	Femenino	0	Soltero(a)	imagen_perfil_generico.jpg	Monitor	{monitor}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	monitor_demo@escuelanora.gob.ve	\N	\N	20	40	\N	\N
32	20000011	Isabella Nicole	Flores Rojas	\N	$2y$10$7b9B6P/CVE78AGV604BpQuriOu.sQEpbp.Xbp20BtEZpmYSSNpoRi	04120000011	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.389149	2000-05-21	Femenino	26		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante11@escuelanora.gob.ve	\N	\N	20	40	\N	\N
2	8845406	ISABEL CRISTINA	MONSALVE MIJARES	kaguilarmonsalve@gmail.com	$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm	04160229542	assets/img/user-default.png	profesor	Activo	\N	2026-10-16 16:50:56.151923	1962-11-26	Femenino	63	Concubinato	foto_user_2_1792546261.jpeg	Profesora (or)	{estudiante}	Ninguna	Ninguna	Carabobo	Bejuma	Bejuma	Calle Valencia Sector San Rafael Casa 2-45	No					No	0	kaguilarmonsalve@gmail.com	\N	\N	20	40	{"Sábado": ["07:00:00", "08:00:00", "09:45:00", "10:30:00"]}	\N
33	20000012	Miguel Angel	Chavez Cordero	\N	$2y$10$uOcat6IhEMNDreiDdbm.gOJ6SD35.H1ZTV9fw8CO6LZfIPZfaz6X.	04120000012	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.47477	1998-10-09	Masculino	28		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante12@escuelanora.gob.ve	\N	\N	20	40	\N	\N
4	99999999	ESTUDIANTE	DE PRUEBA	prueba.estudiante@example.com	$2y$10$nSTU7RtcQN6uYDg4trk/kueKdmS1aBN5hGCzfxpTSJTk.uB2IQn/W	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-10-20 20:03:20.923781	\N	\N	\N	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
34	20000013	Mariana Victoria	Jimenez Silva	\N	$2y$10$feHBJ/LvjvCbahGy8Qfnl.EUT/CBXKsS05PwwGouYJC8l941/d.Wi	04120000013	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.559843	1999-03-27	Femenino	27		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante13@escuelanora.gob.ve	\N	\N	20	40	\N	\N
25	20000004	Luis Fernando	Garcia Torres	\N	$2y$10$z/ZJwSc1DEvkzQ7bWPrtuuwzHtKJMOtb/eeAl17fFdLfKctX9wlue	04120000004	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:05.779667	1996-02-18	Masculino	30		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante04@escuelanora.gob.ve	\N	\N	20	40	\N	\N
26	20000005	Valentina	Diaz Ramirez	\N	$2y$10$htxFKL9euwMtLR3fMPVq4ucJQcFQ8wrGL7n2L3oEJzkkmeOx8HZsK	04120000005	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:05.868869	2000-09-30	Femenino	26		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante05@escuelanora.gob.ve	\N	\N	20	40	\N	\N
27	20000006	Carlos Eduardo	Perez Soto	\N	$2y$10$e52o35lqdMZdoB2R2CBBF.cjpdwwGudrKtXB6i.mAY6dzrcux3Hnu	04120000006	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:05.954579	1998-06-12	Masculino	28		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante06@escuelanora.gob.ve	\N	\N	20	40	\N	\N
28	20000007	Camila Alejandra	Ruiz Mendoza	\N	$2y$10$L//uqVkkN5EvmYF1R4n89uvPeGlYXfV2YrEFxGNtJwZ3q.Tlvuedi	04120000007	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.040212	1999-01-25	Femenino	27		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante07@escuelanora.gob.ve	\N	\N	20	40	\N	\N
29	20000008	Andres David	Lopez Castillo	\N	$2y$10$BYJ99UjUb8ijWqQ5vIraxeiYzIGQAVHdJyJbrHMWJcrTtOGSnP8YO	04120000008	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.128428	1997-04-08	Masculino	29		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante08@escuelanora.gob.ve	\N	\N	20	40	\N	\N
30	20000009	Sofia Isabella	Torres Vargas	\N	$2y$10$oeS6vO11j.KdbSPTVWuiueZxFBqdV7E7NwNMXCp.KcNhQ31dz196.	04120000009	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.216996	2001-08-17	Femenino	25		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante09@escuelanora.gob.ve	\N	\N	20	40	\N	\N
31	20000010	Juan Pablo	Herrera Medina	\N	$2y$10$SD3obYsYpIMflK/QtD5a4uOWqjq.A61Mml4Z0mMdmcsBKIrgh5XrO	04120000010	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.303846	1996-12-03	Masculino	29		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante10@escuelanora.gob.ve	\N	\N	20	40	\N	\N
35	20000014	Diego Alejandro	Ortega Rivas	\N	$2y$10$VDD33OxW.JlsEqRID2rmBelvXrsrGiE1lJH/aXO0qAliR/4zbFkzW	04120000014	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.647958	1997-09-14	Masculino	29		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante14@escuelanora.gob.ve	\N	\N	20	40	\N	\N
36	20000015	Fernanda Lucia	Vargas Leon	\N	$2y$10$UUHpknR07GFj7pGoVwEBlOW3Owh2Jh2TiZUsPytmi/fH1/xtHVykq	04120000015	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.732693	2001-02-06	Femenino	25		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante15@escuelanora.gob.ve	\N	\N	20	40	\N	\N
37	20000016	Omar Enrique	Ramos Gil	\N	$2y$10$IFV10CsatJyTqzARUZ9CX.E1CwZPVbJn72lKb4ldnjar0.De0meei	04120000016	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.818225	1995-11-19	Masculino	31		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante16@escuelanora.gob.ve	\N	\N	20	40	\N	\N
38	20000017	Daniela Paola	Moreno Abreu	\N	$2y$10$jKu/sdFeRd2fd1liqCaa0el0nhpJQUuhA4vHGofoBRh/YdL6vsYVC	04120000017	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.903812	2000-07-02	Femenino	26		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante17@escuelanora.gob.ve	\N	\N	20	40	\N	\N
39	20000018	Sebastian Rafael	Navarro Diaz	\N	$2y$10$.oUvF/cRuTApbnMxGQvMOebkKwzd4E8PYOuiEkXQTceE6ChhagqSm	04120000018	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:06.990049	1998-04-30	Masculino	28		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante18@escuelanora.gob.ve	\N	\N	20	40	\N	\N
40	20000019	Lorena Cristina	Guerrero Pena	\N	$2y$10$4NmAP8/0Sb0X145W8XFm7.3tZnJvJ2jala4br1WiRs338.O2gZXg.	04120000019	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:07.081039	1999-08-11	Femenino	27		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante19@escuelanora.gob.ve	\N	\N	20	40	\N	\N
41	20000020	Rafael Ignacio	Medina Cortes	\N	$2y$10$Eu.oY1cDakSCe/9vjOE8L.VC8AaEkhptnhEFCAcn/VcfaK0UHAxDm	04120000020	assets/img/user-default.png	estudiante	Activo	\N	2026-11-18 23:47:07.167812	1996-06-28	Masculino	30		imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	estudiante20@escuelanora.gob.ve	\N	\N	20	40	\N	\N
48	13720437	DORIS MARITZA	BARONI REALZA	\N	$2y$10$SIzIUy1bZslsYzfHown5MOsvVjgsHY4yTPYmDtHsj7sfibWk8kS1a	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:56.846021	1979-05-15	Femenino	47	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
49	30144935	EILLIN ISMAILIN	NIEVES NOGUERA	\N	$2y$10$VKVvDj1nr2Gut.uiMt/XC.1hFDIamBayeeqKutS5fahwCgMg4VmYm	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:56.931526	2002-11-23	Femenino	23	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
50	23000886	ELIZABETH JOSEFINA	MOLINA ROMERO	\N	$2y$10$JB6e/uRc2qwisggZ2/uBCOLbuN0dyl.IZnnngSHhLcijXV6We3nVu	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.017711	1993-09-12	Femenino	33	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
51	36942723	POR COMPLETAR	POR COMPLETAR	\N	$2y$10$E505gZrcKW/o1Aebi.pmG.wBsavwXRA8vKF6scKuULsJxulFW1VyO	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.103956	\N	\N	\N	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
44	9642972	ANA LUZ	CABALLERO GARCIA	\N	$2y$10$uAPFmu/JZckOtgZHyaocQeTbClc2Px/ErR2rUTna3fg.hNsKoqf3G	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:56.493427	1967-07-11	Femenino	59	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
45	7231029	CARMEN ROSA	LOZANO	\N	$2y$10$6OJN/SEE5tbB6./XFS8Y7uoAlpRLcXJzjF3Cll5W06OPWoFcJo35m	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:56.587191	1962-10-10	Femenino	64	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
46	13200985	CLEY TERESA	PINTO DE BOTELLO	\N	$2y$10$s1nbV9XKE8ksYbDHu7Mb6.5tsvEdcnYArGYUDWB/kfhmid5Be09be	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:56.675519	1976-07-10	Femenino	50	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
47	7181483	CONSUELO RAMONA	MARIN CASTILLO	\N	$2y$10$VrNEeYfmUyU46yL0/huEy.I.J.GFxHVSGKdEKpd/9X.kGHl5ThkpO	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:56.761026	1960-02-05	Femenino	66	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
52	16457280	MARGLORY DEL MAR	ONTIVEROS CASTELLANO	\N	$2y$10$F4g7JNHyrwcmVZfcCHA6r.G4g.gUd4VB4V3Kt/sO5KHigPsB6vGX6	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.188947	1982-12-09	Femenino	43	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
53	16098798	HECYURI ISABEL	GODOY ACOSTA	\N	$2y$10$y/GZRdircDI15mysUbPCluVZMTfhEYBWHp/E9ySJZ9.1qtoys/B66	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.275813	1983-12-26	Femenino	42	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
54	9669641	INGRID	WEIBEZAHN MORILLO	\N	$2y$10$jiwWXW2iVeFos8PCbjFDZu1zbInYghuslQpq6d7QfgWfcaa88hSl.	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.363874	1970-06-18	Femenino	56	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
55	31021190	JOHNNEIDY PAOLA	ORDOСEZ ATENCIO	\N	$2y$10$KM/.4TyYUwNN9OFPNikK0OoZWxopHwYfbnzuU7aq11LxLI0S.B7ii	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.449123	2004-07-05	Femenino	22	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
56	7215797	LIVIA YADIRA	RODRIGUEZ	\N	$2y$10$s0PsBrL3vGm3oFah5EpUaeeyk1jYln7LBmTjqFmINtZjhep3aydi2	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.534666	1961-12-20	Femenino	64	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
57	5280936	MAGALLY MAGDALENA	RODRIGUEZ RAMOS	\N	$2y$10$G.sMxHd2jEz1tLODuHtqRuhy6HMd64IPLcFcJlI2c4JMAggjjYLp.	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.622226	1959-07-22	Femenino	67	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
58	30094993	MARIA ALEJANDRA	ORECHON PEСA	\N	$2y$10$jPpR9JmaJcGpDtVxwRvt/OPn5FgeJrku.7lX/g7nci2lsOS6BV33m	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.714927	2001-05-07	Femenino	25	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
59	31080713	MARIA VICTORIA	BOTELLO PINTO	\N	$2y$10$TqewtOJ0eEQWYyelEqKR9.01I8Cq4IrY4j5tPxid1ycGigdxl9E4e	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.802508	2005-12-06	Femenino	20	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
60	11999709	MARIELBA DEL CARMEN	HERRERA YEGUEZ	\N	$2y$10$Bbo1M7oUBrmc.7jSArh3xOjkGPo3I0vVkaAVL15zgZgmiIn2U0Kru	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.888753	1972-10-15	Femenino	54	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
61	6717945	RAQUEL	MARTINEZ LINARES	\N	$2y$10$6vDk0A6Sr037v/efk2Nile.TM6lSU75VSbJs20fpIhPcCtKMzeknu	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:57.976514	1965-11-21	Femenino	60	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
62	8757587	TIBISAY JOSEFINA	ZULOAGA GONZALEZ	\N	$2y$10$8FnGWiyc6u0XTC3uUyFO8eyFoY9J.pPM8jvEeHEa5G06aOoXf7KvC	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:58.062905	1966-08-27	Femenino	60	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
63	31488125	VICTORIA ALEXANDRA	PEREZ DELGADO	\N	$2y$10$PCCzyNHSNq0mwBoJOd/u7uU2K5dgKsc76yenuQ.N1r29VmvWegM2G	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:58.151997	2006-05-07	Femenino	20	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
64	12298925	YAJAIRA YSABEL	GONZALEZ	\N	$2y$10$7lvSnlvdlTjO/WKwve..Me6K88WWjmp2XVUHLb0vkPjBpLtXVeowy	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:58.239403	1969-01-10	Femenino	57	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
65	20990244	YESSIKA ANDREINA	COLMENARES MARTINEZ	\N	$2y$10$5KsE.PBVMd0b09ePfw2Xue9INbjr9csdg5pHLBVe3nTlPwvgBLypO	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:58.32542	1992-05-05	Femenino	34	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
66	8733020	YILDA YSABEL	APONTE MORENO	\N	$2y$10$TzY57QbA3Dx26FtI8hQlSuv14/pfpnsdgGMgVLAvJoP/2IjcfjJvO	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:58.411069	1964-08-06	Femenino	62	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
67	29786796	YUDESKI DEL VALLE	BRIZUELA COLMENAREZ	\N	$2y$10$ZAJXaLiAd3PbNKgtumCGlOWrOM4tIRuPNiQF5PWcYw/mlSoceNBeq	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 16:32:58.49867	1997-11-08	Femenino	29	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
68	20546388	LUIS ALEXIS	CARMONA BLANCO	\N	$2y$10$6EldGFKVWb4aERI32OdX7.tfN9YX6n7/sseC39Mup/QVuC3fguQVq	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 23:08:53.991735	1991-01-10	Masculino	35	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
69	25884346	DERWIN JOSE	CHIRINOS ZAVARCE	\N	$2y$10$g/hRSYye9FR4QouiqJyzauKP/EMBSBdBz3nUjkimD6XFrWp3cizh6	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 23:09:25.053561	1994-11-04	Masculino	32	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
70	25894741	FRANKLIN ALEXANDER	MUJICA PEСA	\N	$2y$10$r8u.6Z4ghjUZHczxnuvbvezAcjCmhJChMU2eM4IAnJKMCOR.IiCPu	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 23:09:48.048606	1996-06-08	Masculino	30	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
71	16896445	EDUARD ENRIQUE	SOTILLO GUEDEZ	\N	$2y$10$VqapKOTJsrujGsILiVEqpuvjoFf0EnR/Nhnv3smBzTRWkSK/B1YJa	\N	assets/img/user-default.png	estudiante	Activo	\N	2026-11-19 23:12:15.540977	1985-02-08	Masculino	41	\N	imagen_perfil_generico.jpg	Estudiante	{estudiante}	Ninguna	Ninguna	\N	\N	\N	\N	No	\N	\N	\N	\N	No	0	\N	\N	\N	20	40	\N	\N
1	17494175	Kervyn	Aguilar	kervynaguilar26@gmail.com	$2y$10$/fsc4rpNpJ27vUJeVQuHr.qNFa53QgKAj1sJ/2Tj6TTxmtXhiRA.m	04123918270	assets/img/user-default.png	superadmin	Activo	\N	2026-10-13 22:47:57.602992	1985-02-26	Masculino	41	Soltero(a)	foto_user_1_1792433033.jpg	Administrador del Sistema	{superadmin,admin}	Ninguna	Ninguna	Carabobo	Bejuma	Bejuma	Bejuma Calle Valencia Sector San Rafael Casa 2-49\r\nSan Rafael Bejuma	Sí	Carabobo	Bejuma	Bejuma	Escuela Crespo Garrote	Sí	2	kervynaguilar26@gmail.com	\N	\N	20	40	{"Lunes": ["08:00:00", "08:45:00"], "Jueves": ["08:00:00", "08:45:00"], "Martes": ["08:00:00", "08:45:00"], "Sábado": ["08:00:00", "08:45:00", "09:45:00", "10:30:00", "11:15:00"], "Viernes": ["09:45:00", "10:30:00", "11:15:00"], "Miércoles": ["09:45:00", "10:30:00", "11:15:00"]}	\N
\.


--
-- Name: asignaciones_profesor_id_asignacion_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.asignaciones_profesor_id_asignacion_seq', 19, true);


--
-- Name: asistencia_clases_id_asistencia_clase_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.asistencia_clases_id_asistencia_clase_seq', 1, false);


--
-- Name: bitacora_id_log_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.bitacora_id_log_seq', 140, true);


--
-- Name: campus_edificios_id_edificio_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.campus_edificios_id_edificio_seq', 12, true);


--
-- Name: campus_espacios_id_espacio_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.campus_espacios_id_espacio_seq', 24, true);


--
-- Name: carreras_misiones_id_carrera_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.carreras_misiones_id_carrera_seq', 1, false);


--
-- Name: certificado_envios_id_envio_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.certificado_envios_id_envio_seq', 3, true);


--
-- Name: certificados_emitidos_id_certificado_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.certificados_emitidos_id_certificado_seq', 5, true);


--
-- Name: cohortes_id_cohorte_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.cohortes_id_cohorte_seq', 11, true);


--
-- Name: comedor_asistencia_id_asistencia_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.comedor_asistencia_id_asistencia_seq', 27, true);


--
-- Name: comedor_inventario_id_producto_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.comedor_inventario_id_producto_seq', 7, true);


--
-- Name: comedor_menu_id_menu_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.comedor_menu_id_menu_seq', 1, false);


--
-- Name: comedor_movimientos_id_movimiento_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.comedor_movimientos_id_movimiento_seq', 8, true);


--
-- Name: configuracion_autoridades_id_autoridad_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.configuracion_autoridades_id_autoridad_seq', 2, true);


--
-- Name: cursos_id_curso_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.cursos_id_curso_seq', 1, false);


--
-- Name: defensoria_casos_id_caso_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.defensoria_casos_id_caso_seq', 1, true);


--
-- Name: defensoria_seguimiento_id_seguimiento_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.defensoria_seguimiento_id_seguimiento_seq', 2, true);


--
-- Name: discapacidad_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.discapacidad_id_seq', 7, true);


--
-- Name: dormitorio_lista_espera_id_lista_espera_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.dormitorio_lista_espera_id_lista_espera_seq', 4, true);


--
-- Name: dormitorio_mudanzas_id_mudanza_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.dormitorio_mudanzas_id_mudanza_seq', 1, true);


--
-- Name: dormitorio_pernoctas_id_pernocta_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.dormitorio_pernoctas_id_pernocta_seq', 3, true);


--
-- Name: dormitorio_prorrogas_id_prorroga_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.dormitorio_prorrogas_id_prorroga_seq', 1, true);


--
-- Name: dormitorio_responsables_id_responsable_registro_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.dormitorio_responsables_id_responsable_registro_seq', 2, true);


--
-- Name: dormitorios_id_dormitorio_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.dormitorios_id_dormitorio_seq', 4, true);


--
-- Name: etnia_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.etnia_id_seq', 9, true);


--
-- Name: feriados_venezuela_id_feriado_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.feriados_venezuela_id_feriado_seq', 10, true);


--
-- Name: foro_asistencias_id_asistencia_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.foro_asistencias_id_asistencia_seq', 15, true);


--
-- Name: foro_certificados_id_certificado_foro_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.foro_certificados_id_certificado_foro_seq', 4, true);


--
-- Name: foro_informes_id_informe_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.foro_informes_id_informe_seq', 1, true);


--
-- Name: foro_mesa_participantes_id_mesa_participante_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.foro_mesa_participantes_id_mesa_participante_seq', 11, true);


--
-- Name: foro_mesas_id_mesa_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.foro_mesas_id_mesa_seq', 2, true);


--
-- Name: foro_participantes_id_participante_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.foro_participantes_id_participante_seq', 11, true);


--
-- Name: foro_temas_id_tema_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.foro_temas_id_tema_seq', 4, true);


--
-- Name: foros_eventos_id_foro_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.foros_eventos_id_foro_seq', 3, true);


--
-- Name: guarderia_asistencia_id_asistencia_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.guarderia_asistencia_id_asistencia_seq', 1, true);


--
-- Name: guarderia_ninos_id_nino_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.guarderia_ninos_id_nino_seq', 2, true);


--
-- Name: horarios_clases_id_horario_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.horarios_clases_id_horario_seq', 1, false);


--
-- Name: horarios_secciones_id_horario_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.horarios_secciones_id_horario_seq', 239, true);


--
-- Name: inscripciones_id_inscripcion_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.inscripciones_id_inscripcion_seq', 30, true);


--
-- Name: login_intentos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.login_intentos_id_seq', 115, true);


--
-- Name: malla_materias_id_materia_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.malla_materias_id_materia_seq', 58, true);


--
-- Name: mallas_curriculares_id_malla_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.mallas_curriculares_id_malla_seq', 15, true);


--
-- Name: materia_contenidos_id_contenido_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.materia_contenidos_id_contenido_seq', 12, true);


--
-- Name: misiones_estudiantes_id_estudiante_mision_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.misiones_estudiantes_id_estudiante_mision_seq', 1, true);


--
-- Name: notas_estudiantes_id_nota_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notas_estudiantes_id_nota_seq', 22, true);


--
-- Name: plan_evaluacion_contenidos_id_plan_contenido_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.plan_evaluacion_contenidos_id_plan_contenido_seq', 29, true);


--
-- Name: plan_evaluacion_id_plan_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.plan_evaluacion_id_plan_seq', 15, true);


--
-- Name: prestamo_misiones_id_prestamo_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.prestamo_misiones_id_prestamo_seq', 2, true);


--
-- Name: recepcion_alertas_qr_id_alerta_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.recepcion_alertas_qr_id_alerta_seq', 5, true);


--
-- Name: recepcion_areas_id_area_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.recepcion_areas_id_area_seq', 9, true);


--
-- Name: recepcion_visitantes_id_visitante_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.recepcion_visitantes_id_visitante_seq', 3, true);


--
-- Name: recepcion_visitas_id_visita_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.recepcion_visitas_id_visita_seq', 3, true);


--
-- Name: recuperacion_clave_id_recuperacion_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.recuperacion_clave_id_recuperacion_seq', 1, false);


--
-- Name: usuarios_id_usuario_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.usuarios_id_usuario_seq', 71, true);


--
-- Name: alimentos_tabla_venezuela alimentos_tabla_venezuela_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alimentos_tabla_venezuela
    ADD CONSTRAINT alimentos_tabla_venezuela_pkey PRIMARY KEY (codigo_alimento);


--
-- Name: asignaciones_profesor asignaciones_profesor_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asignaciones_profesor
    ADD CONSTRAINT asignaciones_profesor_pkey PRIMARY KEY (id_asignacion);


--
-- Name: asistencia_clases asistencia_clases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asistencia_clases
    ADD CONSTRAINT asistencia_clases_pkey PRIMARY KEY (id_asistencia_clase);


--
-- Name: bitacora bitacora_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora
    ADD CONSTRAINT bitacora_pkey PRIMARY KEY (id_log);


--
-- Name: campus_edificios campus_edificios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campus_edificios
    ADD CONSTRAINT campus_edificios_pkey PRIMARY KEY (id_edificio);


--
-- Name: campus_espacios campus_espacios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campus_espacios
    ADD CONSTRAINT campus_espacios_pkey PRIMARY KEY (id_espacio);


--
-- Name: carreras_misiones carreras_misiones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.carreras_misiones
    ADD CONSTRAINT carreras_misiones_pkey PRIMARY KEY (id_carrera);


--
-- Name: certificado_configuracion certificado_configuracion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_configuracion
    ADD CONSTRAINT certificado_configuracion_pkey PRIMARY KEY (id_config);


--
-- Name: certificado_envios certificado_envios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_envios
    ADD CONSTRAINT certificado_envios_pkey PRIMARY KEY (id_envio);


--
-- Name: certificados_emitidos certificados_emitidos_folio_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificados_emitidos
    ADD CONSTRAINT certificados_emitidos_folio_key UNIQUE (folio);


--
-- Name: certificados_emitidos certificados_emitidos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificados_emitidos
    ADD CONSTRAINT certificados_emitidos_pkey PRIMARY KEY (id_certificado);


--
-- Name: cohortes cohortes_codigo_cohorte_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cohortes
    ADD CONSTRAINT cohortes_codigo_cohorte_key UNIQUE (codigo_cohorte);


--
-- Name: cohortes cohortes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cohortes
    ADD CONSTRAINT cohortes_pkey PRIMARY KEY (id_cohorte);


--
-- Name: comedor_asistencia comedor_asistencia_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_asistencia
    ADD CONSTRAINT comedor_asistencia_pkey PRIMARY KEY (id_asistencia);


--
-- Name: comedor_inventario comedor_inventario_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_inventario
    ADD CONSTRAINT comedor_inventario_pkey PRIMARY KEY (id_producto);


--
-- Name: comedor_menu comedor_menu_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_menu
    ADD CONSTRAINT comedor_menu_pkey PRIMARY KEY (id_menu);


--
-- Name: comedor_movimientos comedor_movimientos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_movimientos
    ADD CONSTRAINT comedor_movimientos_pkey PRIMARY KEY (id_movimiento);


--
-- Name: configuracion_autoridades configuracion_autoridades_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.configuracion_autoridades
    ADD CONSTRAINT configuracion_autoridades_pkey PRIMARY KEY (id_autoridad);


--
-- Name: cursos cursos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cursos
    ADD CONSTRAINT cursos_pkey PRIMARY KEY (id_curso);


--
-- Name: defensoria_casos defensoria_casos_codigo_expediente_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defensoria_casos
    ADD CONSTRAINT defensoria_casos_codigo_expediente_key UNIQUE (codigo_expediente);


--
-- Name: defensoria_casos defensoria_casos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defensoria_casos
    ADD CONSTRAINT defensoria_casos_pkey PRIMARY KEY (id_caso);


--
-- Name: defensoria_seguimiento defensoria_seguimiento_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defensoria_seguimiento
    ADD CONSTRAINT defensoria_seguimiento_pkey PRIMARY KEY (id_seguimiento);


--
-- Name: discapacidad discapacidad_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discapacidad
    ADD CONSTRAINT discapacidad_nombre_key UNIQUE (nombre);


--
-- Name: discapacidad discapacidad_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discapacidad
    ADD CONSTRAINT discapacidad_pkey PRIMARY KEY (id);


--
-- Name: dormitorio_lista_espera dormitorio_lista_espera_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_lista_espera
    ADD CONSTRAINT dormitorio_lista_espera_pkey PRIMARY KEY (id_lista_espera);


--
-- Name: dormitorio_mudanzas dormitorio_mudanzas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_mudanzas
    ADD CONSTRAINT dormitorio_mudanzas_pkey PRIMARY KEY (id_mudanza);


--
-- Name: dormitorio_pernoctas dormitorio_pernoctas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_pernoctas
    ADD CONSTRAINT dormitorio_pernoctas_pkey PRIMARY KEY (id_pernocta);


--
-- Name: dormitorio_prorrogas dormitorio_prorrogas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_prorrogas
    ADD CONSTRAINT dormitorio_prorrogas_pkey PRIMARY KEY (id_prorroga);


--
-- Name: dormitorio_responsables dormitorio_responsables_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_responsables
    ADD CONSTRAINT dormitorio_responsables_pkey PRIMARY KEY (id_responsable_registro);


--
-- Name: dormitorios dormitorios_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorios
    ADD CONSTRAINT dormitorios_nombre_key UNIQUE (nombre);


--
-- Name: dormitorios dormitorios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorios
    ADD CONSTRAINT dormitorios_pkey PRIMARY KEY (id_dormitorio);


--
-- Name: etnia etnia_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.etnia
    ADD CONSTRAINT etnia_nombre_key UNIQUE (nombre);


--
-- Name: etnia etnia_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.etnia
    ADD CONSTRAINT etnia_pkey PRIMARY KEY (id);


--
-- Name: feriados_venezuela feriados_venezuela_fecha_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feriados_venezuela
    ADD CONSTRAINT feriados_venezuela_fecha_key UNIQUE (fecha);


--
-- Name: feriados_venezuela feriados_venezuela_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feriados_venezuela
    ADD CONSTRAINT feriados_venezuela_pkey PRIMARY KEY (id_feriado);


--
-- Name: foro_asistencias foro_asistencias_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_asistencias
    ADD CONSTRAINT foro_asistencias_pkey PRIMARY KEY (id_asistencia);


--
-- Name: foro_certificados foro_certificados_id_participante_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_certificados
    ADD CONSTRAINT foro_certificados_id_participante_key UNIQUE (id_participante);


--
-- Name: foro_certificados foro_certificados_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_certificados
    ADD CONSTRAINT foro_certificados_pkey PRIMARY KEY (id_certificado_foro);


--
-- Name: foro_informes foro_informes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_informes
    ADD CONSTRAINT foro_informes_pkey PRIMARY KEY (id_informe);


--
-- Name: foro_mesa_participantes foro_mesa_participantes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesa_participantes
    ADD CONSTRAINT foro_mesa_participantes_pkey PRIMARY KEY (id_mesa_participante);


--
-- Name: foro_mesas foro_mesas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesas
    ADD CONSTRAINT foro_mesas_pkey PRIMARY KEY (id_mesa);


--
-- Name: foro_participantes foro_participantes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_participantes
    ADD CONSTRAINT foro_participantes_pkey PRIMARY KEY (id_participante);


--
-- Name: foro_temas foro_temas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_temas
    ADD CONSTRAINT foro_temas_pkey PRIMARY KEY (id_tema);


--
-- Name: foros_eventos foros_eventos_codigo_foro_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foros_eventos
    ADD CONSTRAINT foros_eventos_codigo_foro_key UNIQUE (codigo_foro);


--
-- Name: foros_eventos foros_eventos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foros_eventos
    ADD CONSTRAINT foros_eventos_pkey PRIMARY KEY (id_foro);


--
-- Name: guarderia_asistencia guarderia_asistencia_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guarderia_asistencia
    ADD CONSTRAINT guarderia_asistencia_pkey PRIMARY KEY (id_asistencia);


--
-- Name: guarderia_ninos guarderia_ninos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guarderia_ninos
    ADD CONSTRAINT guarderia_ninos_pkey PRIMARY KEY (id_nino);


--
-- Name: horarios_clases horarios_clases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_clases
    ADD CONSTRAINT horarios_clases_pkey PRIMARY KEY (id_horario);


--
-- Name: horarios_clases horarios_clases_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_clases
    ADD CONSTRAINT horarios_clases_unique UNIQUE (id_cohorte, dia_semana, hora_inicio);


--
-- Name: horarios_secciones horarios_secciones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_secciones
    ADD CONSTRAINT horarios_secciones_pkey PRIMARY KEY (id_horario);


--
-- Name: inscripciones inscripciones_folio_certificado_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inscripciones
    ADD CONSTRAINT inscripciones_folio_certificado_key UNIQUE (folio_certificado);


--
-- Name: inscripciones inscripciones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inscripciones
    ADD CONSTRAINT inscripciones_pkey PRIMARY KEY (id_inscripcion);


--
-- Name: login_intentos login_intentos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_intentos
    ADD CONSTRAINT login_intentos_pkey PRIMARY KEY (id);


--
-- Name: malla_materias malla_materias_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.malla_materias
    ADD CONSTRAINT malla_materias_pkey PRIMARY KEY (id_materia);


--
-- Name: mallas_curriculares mallas_curriculares_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mallas_curriculares
    ADD CONSTRAINT mallas_curriculares_pkey PRIMARY KEY (id_malla);


--
-- Name: materia_contenidos materia_contenidos_orden_uq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.materia_contenidos
    ADD CONSTRAINT materia_contenidos_orden_uq UNIQUE (id_materia, orden);


--
-- Name: materia_contenidos materia_contenidos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.materia_contenidos
    ADD CONSTRAINT materia_contenidos_pkey PRIMARY KEY (id_contenido);


--
-- Name: misiones_estudiantes misiones_estudiantes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misiones_estudiantes
    ADD CONSTRAINT misiones_estudiantes_pkey PRIMARY KEY (id_estudiante_mision);


--
-- Name: monitor_sincronizaciones monitor_sincronizaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monitor_sincronizaciones
    ADD CONSTRAINT monitor_sincronizaciones_pkey PRIMARY KEY (uuid_cliente);


--
-- Name: notas_estudiantes notas_estudiantes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notas_estudiantes
    ADD CONSTRAINT notas_estudiantes_pkey PRIMARY KEY (id_nota);


--
-- Name: plan_evaluacion_contenidos plan_eval_contenido_uq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion_contenidos
    ADD CONSTRAINT plan_eval_contenido_uq UNIQUE (id_plan, id_contenido);


--
-- Name: plan_evaluacion_contenidos plan_evaluacion_contenidos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion_contenidos
    ADD CONSTRAINT plan_evaluacion_contenidos_pkey PRIMARY KEY (id_plan_contenido);


--
-- Name: plan_evaluacion plan_evaluacion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion
    ADD CONSTRAINT plan_evaluacion_pkey PRIMARY KEY (id_plan);


--
-- Name: prestamo_misiones prestamo_misiones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prestamo_misiones
    ADD CONSTRAINT prestamo_misiones_pkey PRIMARY KEY (id_prestamo);


--
-- Name: recepcion_alertas_qr recepcion_alertas_qr_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_alertas_qr
    ADD CONSTRAINT recepcion_alertas_qr_pkey PRIMARY KEY (id_alerta);


--
-- Name: recepcion_areas recepcion_areas_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_areas
    ADD CONSTRAINT recepcion_areas_nombre_key UNIQUE (nombre);


--
-- Name: recepcion_areas recepcion_areas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_areas
    ADD CONSTRAINT recepcion_areas_pkey PRIMARY KEY (id_area);


--
-- Name: recepcion_visitantes recepcion_visitantes_cedula_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitantes
    ADD CONSTRAINT recepcion_visitantes_cedula_key UNIQUE (cedula);


--
-- Name: recepcion_visitantes recepcion_visitantes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitantes
    ADD CONSTRAINT recepcion_visitantes_pkey PRIMARY KEY (id_visitante);


--
-- Name: recepcion_visitas recepcion_visitas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitas
    ADD CONSTRAINT recepcion_visitas_pkey PRIMARY KEY (id_visita);


--
-- Name: recuperacion_clave recuperacion_clave_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recuperacion_clave
    ADD CONSTRAINT recuperacion_clave_pkey PRIMARY KEY (id_recuperacion);


--
-- Name: asistencia_clases unique_asistencia_dia; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asistencia_clases
    ADD CONSTRAINT unique_asistencia_dia UNIQUE (id_cohorte, id_estudiante, fecha_clase);


--
-- Name: misiones_estudiantes unique_cedula_mision; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misiones_estudiantes
    ADD CONSTRAINT unique_cedula_mision UNIQUE (id_prestamo, cedula);


--
-- Name: comedor_asistencia unique_comensal_dia_turno; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_asistencia
    ADD CONSTRAINT unique_comensal_dia_turno UNIQUE (id_usuario, fecha_registro, turno);


--
-- Name: inscripciones unique_estudiante_curso; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inscripciones
    ADD CONSTRAINT unique_estudiante_curso UNIQUE (id_curso, id_estudiante);


--
-- Name: notas_estudiantes unique_estudiante_plan; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notas_estudiantes
    ADD CONSTRAINT unique_estudiante_plan UNIQUE (id_plan, id_estudiante);


--
-- Name: comedor_menu unique_menu_fecha_turno; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_menu
    ADD CONSTRAINT unique_menu_fecha_turno UNIQUE (fecha, turno_comida);


--
-- Name: asignaciones_profesor unique_profesor_materia_cohorte; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asignaciones_profesor
    ADD CONSTRAINT unique_profesor_materia_cohorte UNIQUE (id_profesor, id_materia, id_cohorte);


--
-- Name: foro_asistencias uq_foro_asistencia; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_asistencias
    ADD CONSTRAINT uq_foro_asistencia UNIQUE (id_participante, tipo_asistencia);


--
-- Name: foro_informes uq_foro_informe; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_informes
    ADD CONSTRAINT uq_foro_informe UNIQUE (id_mesa, id_participante);


--
-- Name: foro_mesas uq_foro_mesa_numero; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesas
    ADD CONSTRAINT uq_foro_mesa_numero UNIQUE (id_foro, numero_mesa);


--
-- Name: foro_mesa_participantes uq_foro_mesa_participante; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesa_participantes
    ADD CONSTRAINT uq_foro_mesa_participante UNIQUE (id_mesa, id_participante);


--
-- Name: foro_participantes uq_foro_participante; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_participantes
    ADD CONSTRAINT uq_foro_participante UNIQUE (id_foro, id_estudiante);


--
-- Name: usuarios usuarios_cedula_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_cedula_key UNIQUE (cedula);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id_usuario);


--
-- Name: certificado_unico_academico; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX certificado_unico_academico ON public.certificados_emitidos USING btree (id_estudiante, id_malla, tipo) WHERE (((origen)::text = 'Academico'::text) AND (id_malla IS NOT NULL));


--
-- Name: idx_campus_espacios_id_edificio; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_campus_espacios_id_edificio ON public.campus_espacios USING btree (id_edificio);


--
-- Name: idx_casos_codigo; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_casos_codigo ON public.defensoria_casos USING btree (codigo_expediente);


--
-- Name: idx_certificado_envios_cert; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_certificado_envios_cert ON public.certificado_envios USING btree (id_certificado);


--
-- Name: idx_certificados_codigo_validacion; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_certificados_codigo_validacion ON public.certificados_emitidos USING btree (codigo_validacion);


--
-- Name: idx_certificados_estudiante; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_certificados_estudiante ON public.certificados_emitidos USING btree (id_estudiante);


--
-- Name: idx_certificados_foro; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_certificados_foro ON public.certificados_emitidos USING btree (id_foro);


--
-- Name: idx_certificados_inscripcion; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_certificados_inscripcion ON public.certificados_emitidos USING btree (id_inscripcion);


--
-- Name: idx_comedor_asistencia_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_comedor_asistencia_fecha ON public.comedor_asistencia USING btree (fecha_registro);


--
-- Name: idx_dormitorio_prorrogas_pernocta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dormitorio_prorrogas_pernocta ON public.dormitorio_prorrogas USING btree (id_pernocta);


--
-- Name: idx_dormitorio_responsables_usuario; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dormitorio_responsables_usuario ON public.dormitorio_responsables USING btree (id_usuario);


--
-- Name: idx_foro_informes_mesa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_foro_informes_mesa ON public.foro_informes USING btree (id_mesa);


--
-- Name: idx_foro_mesas_transcriptor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_foro_mesas_transcriptor ON public.foro_mesas USING btree (id_transcriptor);


--
-- Name: idx_foro_participantes_estudiante; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_foro_participantes_estudiante ON public.foro_participantes USING btree (id_estudiante);


--
-- Name: idx_foro_temas_foro; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_foro_temas_foro ON public.foro_temas USING btree (id_foro);


--
-- Name: idx_guarderia_asis_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_guarderia_asis_fecha ON public.guarderia_asistencia USING btree (fecha_asistencia);


--
-- Name: idx_horarios_clases_cohorte; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_horarios_clases_cohorte ON public.horarios_clases USING btree (id_cohorte);


--
-- Name: idx_login_intentos_busqueda; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_login_intentos_busqueda ON public.login_intentos USING btree (ip, cedula, fecha DESC);


--
-- Name: idx_materia_contenidos_materia; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_materia_contenidos_materia ON public.materia_contenidos USING btree (id_materia);


--
-- Name: idx_monitor_sync_modulo_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_monitor_sync_modulo_fecha ON public.monitor_sincronizaciones USING btree (modulo, fecha_servidor);


--
-- Name: idx_pernoctas_cohorte; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pernoctas_cohorte ON public.dormitorio_pernoctas USING btree (id_cohorte);


--
-- Name: idx_pernoctas_dormitorio; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pernoctas_dormitorio ON public.dormitorio_pernoctas USING btree (id_dormitorio);


--
-- Name: idx_pernoctas_entrada; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pernoctas_entrada ON public.dormitorio_pernoctas USING btree (fecha_entrada);


--
-- Name: idx_pernoctas_estado; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pernoctas_estado ON public.dormitorio_pernoctas USING btree (estado);


--
-- Name: idx_plan_eval_contenidos_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_plan_eval_contenidos_plan ON public.plan_evaluacion_contenidos USING btree (id_plan);


--
-- Name: idx_prorrogas_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_prorrogas_fecha ON public.dormitorio_prorrogas USING btree (fecha_registro);


--
-- Name: idx_prorrogas_pernocta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_prorrogas_pernocta ON public.dormitorio_prorrogas USING btree (id_pernocta);


--
-- Name: idx_recepcion_alertas_atendida; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_recepcion_alertas_atendida ON public.recepcion_alertas_qr USING btree (atendida, fecha_lectura);


--
-- Name: idx_recepcion_visitas_estatus; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_recepcion_visitas_estatus ON public.recepcion_visitas USING btree (estatus);


--
-- Name: idx_recepcion_visitas_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_recepcion_visitas_fecha ON public.recepcion_visitas USING btree (fecha_entrada);


--
-- Name: idx_usuarios_cedula; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_usuarios_cedula ON public.usuarios USING btree (cedula);


--
-- Name: idx_usuarios_email; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_usuarios_email ON public.usuarios USING btree (email);


--
-- Name: uq_dormitorio_responsable_activo; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_dormitorio_responsable_activo ON public.dormitorio_responsables USING btree (id_dormitorio) WHERE (fecha_hasta IS NULL);


--
-- Name: uq_lista_espera_cohorte; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_lista_espera_cohorte ON public.dormitorio_lista_espera USING btree (id_cohorte, cedula) WHERE (id_cohorte IS NOT NULL);


--
-- Name: uq_lista_espera_foro; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_lista_espera_foro ON public.dormitorio_lista_espera USING btree (id_foro, cedula) WHERE (id_foro IS NOT NULL);


--
-- Name: horarios_secciones trg_validar_carga_docente; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_validar_carga_docente BEFORE INSERT OR UPDATE ON public.horarios_secciones FOR EACH ROW EXECUTE FUNCTION public.validar_carga_docente();


--
-- Name: asignaciones_profesor asignaciones_profesor_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asignaciones_profesor
    ADD CONSTRAINT asignaciones_profesor_id_cohorte_fkey FOREIGN KEY (id_cohorte) REFERENCES public.cohortes(id_cohorte) ON DELETE CASCADE;


--
-- Name: asignaciones_profesor asignaciones_profesor_id_materia_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asignaciones_profesor
    ADD CONSTRAINT asignaciones_profesor_id_materia_fkey FOREIGN KEY (id_materia) REFERENCES public.malla_materias(id_materia) ON DELETE CASCADE;


--
-- Name: asignaciones_profesor asignaciones_profesor_id_profesor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asignaciones_profesor
    ADD CONSTRAINT asignaciones_profesor_id_profesor_fkey FOREIGN KEY (id_profesor) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- Name: asistencia_clases asistencia_clases_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asistencia_clases
    ADD CONSTRAINT asistencia_clases_id_cohorte_fkey FOREIGN KEY (id_cohorte) REFERENCES public.cohortes(id_cohorte) ON DELETE CASCADE;


--
-- Name: asistencia_clases asistencia_clases_id_estudiante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.asistencia_clases
    ADD CONSTRAINT asistencia_clases_id_estudiante_fkey FOREIGN KEY (id_estudiante) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- Name: bitacora bitacora_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora
    ADD CONSTRAINT bitacora_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: campus_espacios campus_espacios_id_edificio_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campus_espacios
    ADD CONSTRAINT campus_espacios_id_edificio_fkey FOREIGN KEY (id_edificio) REFERENCES public.campus_edificios(id_edificio) ON DELETE CASCADE;


--
-- Name: certificado_configuracion certificado_configuracion_id_autoridad_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_configuracion
    ADD CONSTRAINT certificado_configuracion_id_autoridad_fkey FOREIGN KEY (id_autoridad) REFERENCES public.configuracion_autoridades(id_autoridad) ON DELETE SET NULL;


--
-- Name: certificado_envios certificado_envios_id_certificado_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_envios
    ADD CONSTRAINT certificado_envios_id_certificado_fkey FOREIGN KEY (id_certificado) REFERENCES public.certificados_emitidos(id_certificado) ON DELETE CASCADE;


--
-- Name: certificados_emitidos certificados_emitidos_id_estudiante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificados_emitidos
    ADD CONSTRAINT certificados_emitidos_id_estudiante_fkey FOREIGN KEY (id_estudiante) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- Name: certificados_emitidos certificados_emitidos_id_foro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificados_emitidos
    ADD CONSTRAINT certificados_emitidos_id_foro_fkey FOREIGN KEY (id_foro) REFERENCES public.foros_eventos(id_foro) ON DELETE SET NULL;


--
-- Name: certificados_emitidos certificados_emitidos_id_inscripcion_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificados_emitidos
    ADD CONSTRAINT certificados_emitidos_id_inscripcion_fkey FOREIGN KEY (id_inscripcion) REFERENCES public.inscripciones(id_inscripcion) ON DELETE CASCADE;


--
-- Name: certificados_emitidos certificados_emitidos_id_malla_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificados_emitidos
    ADD CONSTRAINT certificados_emitidos_id_malla_fkey FOREIGN KEY (id_malla) REFERENCES public.mallas_curriculares(id_malla) ON DELETE CASCADE;


--
-- Name: certificados_emitidos certificados_emitidos_id_usuario_emisor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificados_emitidos
    ADD CONSTRAINT certificados_emitidos_id_usuario_emisor_fkey FOREIGN KEY (id_usuario_emisor) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: cohortes cohortes_id_malla_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cohortes
    ADD CONSTRAINT cohortes_id_malla_fkey FOREIGN KEY (id_malla) REFERENCES public.mallas_curriculares(id_malla) ON DELETE CASCADE;


--
-- Name: cohortes cohortes_id_profesor_guia_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cohortes
    ADD CONSTRAINT cohortes_id_profesor_guia_fkey FOREIGN KEY (id_profesor_guia) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: comedor_asistencia comedor_asistencia_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_asistencia
    ADD CONSTRAINT comedor_asistencia_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- Name: comedor_inventario comedor_inventario_codigo_alimento_inn_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_inventario
    ADD CONSTRAINT comedor_inventario_codigo_alimento_inn_fkey FOREIGN KEY (codigo_alimento_inn) REFERENCES public.alimentos_tabla_venezuela(codigo_alimento) ON DELETE SET NULL;


--
-- Name: comedor_movimientos comedor_movimientos_id_producto_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_movimientos
    ADD CONSTRAINT comedor_movimientos_id_producto_fkey FOREIGN KEY (id_producto) REFERENCES public.comedor_inventario(id_producto) ON DELETE CASCADE;


--
-- Name: comedor_movimientos comedor_movimientos_id_usuario_registro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comedor_movimientos
    ADD CONSTRAINT comedor_movimientos_id_usuario_registro_fkey FOREIGN KEY (id_usuario_registro) REFERENCES public.usuarios(id_usuario);


--
-- Name: cursos cursos_id_profesor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cursos
    ADD CONSTRAINT cursos_id_profesor_fkey FOREIGN KEY (id_profesor) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: defensoria_casos defensoria_casos_id_defensora_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defensoria_casos
    ADD CONSTRAINT defensoria_casos_id_defensora_fkey FOREIGN KEY (id_defensora) REFERENCES public.usuarios(id_usuario) ON DELETE RESTRICT;


--
-- Name: defensoria_seguimiento defensoria_seguimiento_id_caso_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defensoria_seguimiento
    ADD CONSTRAINT defensoria_seguimiento_id_caso_fkey FOREIGN KEY (id_caso) REFERENCES public.defensoria_casos(id_caso) ON DELETE CASCADE;


--
-- Name: defensoria_seguimiento defensoria_seguimiento_id_responsable_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.defensoria_seguimiento
    ADD CONSTRAINT defensoria_seguimiento_id_responsable_fkey FOREIGN KEY (id_responsable) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: dormitorio_lista_espera dormitorio_lista_espera_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_lista_espera
    ADD CONSTRAINT dormitorio_lista_espera_id_cohorte_fkey FOREIGN KEY (id_cohorte) REFERENCES public.cohortes(id_cohorte) ON DELETE CASCADE;


--
-- Name: dormitorio_lista_espera dormitorio_lista_espera_id_foro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_lista_espera
    ADD CONSTRAINT dormitorio_lista_espera_id_foro_fkey FOREIGN KEY (id_foro) REFERENCES public.foros_eventos(id_foro) ON DELETE CASCADE;


--
-- Name: dormitorio_lista_espera dormitorio_lista_espera_id_pernocta_asignada_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_lista_espera
    ADD CONSTRAINT dormitorio_lista_espera_id_pernocta_asignada_fkey FOREIGN KEY (id_pernocta_asignada) REFERENCES public.dormitorio_pernoctas(id_pernocta) ON DELETE SET NULL;


--
-- Name: dormitorio_lista_espera dormitorio_lista_espera_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_lista_espera
    ADD CONSTRAINT dormitorio_lista_espera_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: dormitorio_lista_espera dormitorio_lista_espera_id_usuario_registro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_lista_espera
    ADD CONSTRAINT dormitorio_lista_espera_id_usuario_registro_fkey FOREIGN KEY (id_usuario_registro) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: dormitorio_mudanzas dormitorio_mudanzas_id_dormitorio_destino_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_mudanzas
    ADD CONSTRAINT dormitorio_mudanzas_id_dormitorio_destino_fkey FOREIGN KEY (id_dormitorio_destino) REFERENCES public.dormitorios(id_dormitorio);


--
-- Name: dormitorio_mudanzas dormitorio_mudanzas_id_dormitorio_origen_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_mudanzas
    ADD CONSTRAINT dormitorio_mudanzas_id_dormitorio_origen_fkey FOREIGN KEY (id_dormitorio_origen) REFERENCES public.dormitorios(id_dormitorio);


--
-- Name: dormitorio_mudanzas dormitorio_mudanzas_id_pernocta_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_mudanzas
    ADD CONSTRAINT dormitorio_mudanzas_id_pernocta_fkey FOREIGN KEY (id_pernocta) REFERENCES public.dormitorio_pernoctas(id_pernocta) ON DELETE CASCADE;


--
-- Name: dormitorio_mudanzas dormitorio_mudanzas_id_usuario_registro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_mudanzas
    ADD CONSTRAINT dormitorio_mudanzas_id_usuario_registro_fkey FOREIGN KEY (id_usuario_registro) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: dormitorio_pernoctas dormitorio_pernoctas_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_pernoctas
    ADD CONSTRAINT dormitorio_pernoctas_id_cohorte_fkey FOREIGN KEY (id_cohorte) REFERENCES public.cohortes(id_cohorte) ON DELETE SET NULL;


--
-- Name: dormitorio_pernoctas dormitorio_pernoctas_id_dormitorio_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_pernoctas
    ADD CONSTRAINT dormitorio_pernoctas_id_dormitorio_fkey FOREIGN KEY (id_dormitorio) REFERENCES public.dormitorios(id_dormitorio) ON DELETE CASCADE;


--
-- Name: dormitorio_pernoctas dormitorio_pernoctas_id_foro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_pernoctas
    ADD CONSTRAINT dormitorio_pernoctas_id_foro_fkey FOREIGN KEY (id_foro) REFERENCES public.foros_eventos(id_foro) ON DELETE SET NULL;


--
-- Name: dormitorio_pernoctas dormitorio_pernoctas_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_pernoctas
    ADD CONSTRAINT dormitorio_pernoctas_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: dormitorio_pernoctas dormitorio_pernoctas_id_usuario_registro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_pernoctas
    ADD CONSTRAINT dormitorio_pernoctas_id_usuario_registro_fkey FOREIGN KEY (id_usuario_registro) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: dormitorio_prorrogas dormitorio_prorrogas_id_pernocta_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_prorrogas
    ADD CONSTRAINT dormitorio_prorrogas_id_pernocta_fkey FOREIGN KEY (id_pernocta) REFERENCES public.dormitorio_pernoctas(id_pernocta) ON DELETE CASCADE;


--
-- Name: dormitorio_prorrogas dormitorio_prorrogas_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_prorrogas
    ADD CONSTRAINT dormitorio_prorrogas_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: dormitorio_responsables dormitorio_responsables_id_dormitorio_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_responsables
    ADD CONSTRAINT dormitorio_responsables_id_dormitorio_fkey FOREIGN KEY (id_dormitorio) REFERENCES public.dormitorios(id_dormitorio) ON DELETE CASCADE;


--
-- Name: dormitorio_responsables dormitorio_responsables_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_responsables
    ADD CONSTRAINT dormitorio_responsables_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE RESTRICT;


--
-- Name: dormitorio_responsables dormitorio_responsables_id_usuario_registro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorio_responsables
    ADD CONSTRAINT dormitorio_responsables_id_usuario_registro_fkey FOREIGN KEY (id_usuario_registro) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: dormitorios dormitorios_id_responsable_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dormitorios
    ADD CONSTRAINT dormitorios_id_responsable_fkey FOREIGN KEY (id_responsable) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: foro_asistencias foro_asistencias_id_participante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_asistencias
    ADD CONSTRAINT foro_asistencias_id_participante_fkey FOREIGN KEY (id_participante) REFERENCES public.foro_participantes(id_participante) ON DELETE CASCADE;


--
-- Name: foro_asistencias foro_asistencias_registrado_por_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_asistencias
    ADD CONSTRAINT foro_asistencias_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: foro_certificados foro_certificados_id_certificado_emitido_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_certificados
    ADD CONSTRAINT foro_certificados_id_certificado_emitido_fkey FOREIGN KEY (id_certificado_emitido) REFERENCES public.certificados_emitidos(id_certificado) ON DELETE SET NULL;


--
-- Name: foro_certificados foro_certificados_id_participante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_certificados
    ADD CONSTRAINT foro_certificados_id_participante_fkey FOREIGN KEY (id_participante) REFERENCES public.foro_participantes(id_participante) ON DELETE CASCADE;


--
-- Name: foro_certificados foro_certificados_id_usuario_emisor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_certificados
    ADD CONSTRAINT foro_certificados_id_usuario_emisor_fkey FOREIGN KEY (id_usuario_emisor) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: foro_informes foro_informes_id_mesa_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_informes
    ADD CONSTRAINT foro_informes_id_mesa_fkey FOREIGN KEY (id_mesa) REFERENCES public.foro_mesas(id_mesa) ON DELETE CASCADE;


--
-- Name: foro_informes foro_informes_id_participante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_informes
    ADD CONSTRAINT foro_informes_id_participante_fkey FOREIGN KEY (id_participante) REFERENCES public.foro_participantes(id_participante) ON DELETE CASCADE;


--
-- Name: foro_mesa_participantes foro_mesa_participantes_id_mesa_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesa_participantes
    ADD CONSTRAINT foro_mesa_participantes_id_mesa_fkey FOREIGN KEY (id_mesa) REFERENCES public.foro_mesas(id_mesa) ON DELETE CASCADE;


--
-- Name: foro_mesa_participantes foro_mesa_participantes_id_participante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesa_participantes
    ADD CONSTRAINT foro_mesa_participantes_id_participante_fkey FOREIGN KEY (id_participante) REFERENCES public.foro_participantes(id_participante) ON DELETE CASCADE;


--
-- Name: foro_mesas foro_mesas_id_foro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesas
    ADD CONSTRAINT foro_mesas_id_foro_fkey FOREIGN KEY (id_foro) REFERENCES public.foros_eventos(id_foro) ON DELETE CASCADE;


--
-- Name: foro_mesas foro_mesas_id_transcriptor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_mesas
    ADD CONSTRAINT foro_mesas_id_transcriptor_fkey FOREIGN KEY (id_transcriptor) REFERENCES public.usuarios(id_usuario) ON DELETE RESTRICT;


--
-- Name: foro_participantes foro_participantes_id_estudiante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_participantes
    ADD CONSTRAINT foro_participantes_id_estudiante_fkey FOREIGN KEY (id_estudiante) REFERENCES public.usuarios(id_usuario) ON DELETE RESTRICT;


--
-- Name: foro_participantes foro_participantes_id_foro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_participantes
    ADD CONSTRAINT foro_participantes_id_foro_fkey FOREIGN KEY (id_foro) REFERENCES public.foros_eventos(id_foro) ON DELETE CASCADE;


--
-- Name: foro_participantes foro_participantes_id_usuario_registro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_participantes
    ADD CONSTRAINT foro_participantes_id_usuario_registro_fkey FOREIGN KEY (id_usuario_registro) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: foro_temas foro_temas_id_foro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foro_temas
    ADD CONSTRAINT foro_temas_id_foro_fkey FOREIGN KEY (id_foro) REFERENCES public.foros_eventos(id_foro) ON DELETE CASCADE;


--
-- Name: foros_eventos foros_eventos_id_malla_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foros_eventos
    ADD CONSTRAINT foros_eventos_id_malla_fkey FOREIGN KEY (id_malla) REFERENCES public.mallas_curriculares(id_malla) ON DELETE SET NULL;


--
-- Name: foros_eventos foros_eventos_id_usuario_creador_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.foros_eventos
    ADD CONSTRAINT foros_eventos_id_usuario_creador_fkey FOREIGN KEY (id_usuario_creador) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: guarderia_asistencia guarderia_asistencia_id_nino_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guarderia_asistencia
    ADD CONSTRAINT guarderia_asistencia_id_nino_fkey FOREIGN KEY (id_nino) REFERENCES public.guarderia_ninos(id_nino) ON DELETE CASCADE;


--
-- Name: guarderia_ninos guarderia_ninos_id_representante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guarderia_ninos
    ADD CONSTRAINT guarderia_ninos_id_representante_fkey FOREIGN KEY (id_representante) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- Name: horarios_clases horarios_clases_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_clases
    ADD CONSTRAINT horarios_clases_id_cohorte_fkey FOREIGN KEY (id_cohorte) REFERENCES public.cohortes(id_cohorte) ON DELETE CASCADE;


--
-- Name: horarios_clases horarios_clases_id_materia_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_clases
    ADD CONSTRAINT horarios_clases_id_materia_fkey FOREIGN KEY (id_materia) REFERENCES public.malla_materias(id_materia) ON DELETE SET NULL;


--
-- Name: horarios_secciones horarios_secciones_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_secciones
    ADD CONSTRAINT horarios_secciones_id_cohorte_fkey FOREIGN KEY (id_cohorte) REFERENCES public.cohortes(id_cohorte) ON DELETE CASCADE;


--
-- Name: horarios_secciones horarios_secciones_id_materia_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_secciones
    ADD CONSTRAINT horarios_secciones_id_materia_fkey FOREIGN KEY (id_materia) REFERENCES public.malla_materias(id_materia) ON DELETE CASCADE;


--
-- Name: horarios_secciones horarios_secciones_id_profesor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_secciones
    ADD CONSTRAINT horarios_secciones_id_profesor_fkey FOREIGN KEY (id_profesor) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: inscripciones inscripciones_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inscripciones
    ADD CONSTRAINT inscripciones_id_cohorte_fkey FOREIGN KEY (id_curso) REFERENCES public.cohortes(id_cohorte) ON DELETE CASCADE;


--
-- Name: inscripciones inscripciones_id_discapacidad_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inscripciones
    ADD CONSTRAINT inscripciones_id_discapacidad_fkey FOREIGN KEY (id_discapacidad) REFERENCES public.discapacidad(id);


--
-- Name: inscripciones inscripciones_id_estudiante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inscripciones
    ADD CONSTRAINT inscripciones_id_estudiante_fkey FOREIGN KEY (id_estudiante) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- Name: inscripciones inscripciones_id_etnia_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inscripciones
    ADD CONSTRAINT inscripciones_id_etnia_fkey FOREIGN KEY (id_etnia) REFERENCES public.etnia(id);


--
-- Name: malla_materias malla_materias_id_malla_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.malla_materias
    ADD CONSTRAINT malla_materias_id_malla_fkey FOREIGN KEY (id_malla) REFERENCES public.mallas_curriculares(id_malla) ON DELETE CASCADE;


--
-- Name: materia_contenidos materia_contenidos_id_materia_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.materia_contenidos
    ADD CONSTRAINT materia_contenidos_id_materia_fkey FOREIGN KEY (id_materia) REFERENCES public.malla_materias(id_materia) ON DELETE CASCADE;


--
-- Name: misiones_estudiantes misiones_estudiantes_id_discapacidad_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misiones_estudiantes
    ADD CONSTRAINT misiones_estudiantes_id_discapacidad_fkey FOREIGN KEY (id_discapacidad) REFERENCES public.discapacidad(id) ON DELETE SET NULL;


--
-- Name: misiones_estudiantes misiones_estudiantes_id_etnia_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misiones_estudiantes
    ADD CONSTRAINT misiones_estudiantes_id_etnia_fkey FOREIGN KEY (id_etnia) REFERENCES public.etnia(id) ON DELETE SET NULL;


--
-- Name: misiones_estudiantes misiones_estudiantes_id_prestamo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.misiones_estudiantes
    ADD CONSTRAINT misiones_estudiantes_id_prestamo_fkey FOREIGN KEY (id_prestamo) REFERENCES public.prestamo_misiones(id_prestamo) ON DELETE CASCADE;


--
-- Name: monitor_sincronizaciones monitor_sincronizaciones_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monitor_sincronizaciones
    ADD CONSTRAINT monitor_sincronizaciones_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- Name: notas_estudiantes notas_estudiantes_id_estudiante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notas_estudiantes
    ADD CONSTRAINT notas_estudiantes_id_estudiante_fkey FOREIGN KEY (id_estudiante) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- Name: notas_estudiantes notas_estudiantes_id_plan_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notas_estudiantes
    ADD CONSTRAINT notas_estudiantes_id_plan_fkey FOREIGN KEY (id_plan) REFERENCES public.plan_evaluacion(id_plan) ON DELETE CASCADE;


--
-- Name: plan_evaluacion_contenidos plan_evaluacion_contenidos_id_contenido_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion_contenidos
    ADD CONSTRAINT plan_evaluacion_contenidos_id_contenido_fkey FOREIGN KEY (id_contenido) REFERENCES public.materia_contenidos(id_contenido) ON DELETE CASCADE;


--
-- Name: plan_evaluacion_contenidos plan_evaluacion_contenidos_id_plan_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion_contenidos
    ADD CONSTRAINT plan_evaluacion_contenidos_id_plan_fkey FOREIGN KEY (id_plan) REFERENCES public.plan_evaluacion(id_plan) ON DELETE CASCADE;


--
-- Name: plan_evaluacion plan_evaluacion_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion
    ADD CONSTRAINT plan_evaluacion_id_cohorte_fkey FOREIGN KEY (id_cohorte) REFERENCES public.cohortes(id_cohorte) ON DELETE CASCADE;


--
-- Name: plan_evaluacion plan_evaluacion_id_materia_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion
    ADD CONSTRAINT plan_evaluacion_id_materia_fkey FOREIGN KEY (id_materia) REFERENCES public.malla_materias(id_materia) ON DELETE CASCADE;


--
-- Name: plan_evaluacion plan_evaluacion_id_profesor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_evaluacion
    ADD CONSTRAINT plan_evaluacion_id_profesor_fkey FOREIGN KEY (id_profesor) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: recepcion_alertas_qr recepcion_alertas_qr_id_usuario_atencion_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_alertas_qr
    ADD CONSTRAINT recepcion_alertas_qr_id_usuario_atencion_fkey FOREIGN KEY (id_usuario_atencion) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: recepcion_alertas_qr recepcion_alertas_qr_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_alertas_qr
    ADD CONSTRAINT recepcion_alertas_qr_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: recepcion_visitantes recepcion_visitantes_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitantes
    ADD CONSTRAINT recepcion_visitantes_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: recepcion_visitas recepcion_visitas_id_area_destino_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitas
    ADD CONSTRAINT recepcion_visitas_id_area_destino_fkey FOREIGN KEY (id_area_destino) REFERENCES public.recepcion_areas(id_area);


--
-- Name: recepcion_visitas recepcion_visitas_id_cohorte_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitas
    ADD CONSTRAINT recepcion_visitas_id_cohorte_fkey FOREIGN KEY (id_cohorte) REFERENCES public.cohortes(id_cohorte) ON DELETE SET NULL;


--
-- Name: recepcion_visitas recepcion_visitas_id_usuario_registro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitas
    ADD CONSTRAINT recepcion_visitas_id_usuario_registro_fkey FOREIGN KEY (id_usuario_registro) REFERENCES public.usuarios(id_usuario) ON DELETE SET NULL;


--
-- Name: recepcion_visitas recepcion_visitas_id_visitante_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recepcion_visitas
    ADD CONSTRAINT recepcion_visitas_id_visitante_fkey FOREIGN KEY (id_visitante) REFERENCES public.recepcion_visitantes(id_visitante) ON DELETE CASCADE;


--
-- Name: recuperacion_clave recuperacion_clave_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recuperacion_clave
    ADD CONSTRAINT recuperacion_clave_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict qUqme1GsaAN488FktuozSTHXQgRRb3sU4PUuITcI3nJklcwr8Oa00Bm9cXwWZJH

