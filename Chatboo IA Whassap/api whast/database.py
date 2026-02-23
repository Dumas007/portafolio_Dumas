import mysql.connector
import logging
from datetime import datetime
import time

# Configurar logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def get_connection():
    """Conexión simplificada - solo parámetros esenciales"""
    try:
        conn = mysql.connector.connect(
            host="srv764.hstgr.io",
            user="u960874690_Dgpt007", 
            password="Dumas$007",
            database="u960874690_Dumas_Gpt"
        )
        logger.info("✅ Conexión a BD establecida")
        return conn
    except Exception as e:
        logger.error(f"❌ Error de conexión: {e}")
        return None

def verificar_estado_bd():
    """Verifica el estado de la conexión a la BD"""
    try:
        conn = get_connection()
        if conn and conn.is_connected():
            cursor = conn.cursor()
            cursor.execute("SELECT NOW() as hora_servidor")
            resultado = cursor.fetchone()
            logger.info(f"🕒 Hora del servidor BD: {resultado[0]}")
            cursor.close()
            conn.close()
            return True
        return False
    except Exception as e:
        logger.error(f"❌ Error verificando estado BD: {e}")
        return False

# ... (el resto de las funciones se mantienen igual que en la versión anterior)

def verificar_y_reconectar(conn):
    """Verifica si la conexión está activa y la reconecta si es necesario"""
    try:
        if conn and conn.is_connected():
            cursor = conn.cursor()
            cursor.execute("SELECT 1")
            cursor.close()
            return True
        return False
    except mysql.connector.Error:
        return False

def buscar_respuesta_inteligente(pregunta_usuario):
    """Busca la respuesta más relevante en la tabla preguntas"""
    conn = None
    try:
        conn = get_connection()
        if not conn:
            logger.error("❌ No se pudo conectar a la base de datos")
            return None
            
        cursor = conn.cursor(dictionary=True)
        
        # Limpiar la pregunta
        pregunta_limpia = pregunta_usuario.lower().strip()
        logger.info(f"🔍 Buscando FAQ para: '{pregunta_limpia}'")
        
        # ESTRATEGIA 1: Búsqueda directa en tabla 'preguntas'
        sql_preguntas = """
            SELECT id, pregunta, respuesta, categoria 
            FROM preguntas 
            WHERE activo = 'si' 
            AND (LOWER(pregunta) LIKE %s OR LOWER(palabras_clave) LIKE %s)
            LIMIT 1
        """
        
        busqueda = f"%{pregunta_limpia}%"
        cursor.execute(sql_preguntas, (busqueda, busqueda))
        resultado = cursor.fetchone()
        
        if resultado:
            logger.info(f"✅ Respuesta encontrada en FAQ: {resultado['categoria']}")
            return resultado
        
        # ESTRATEGIA 2: Búsqueda por palabras clave
        palabras = pregunta_limpia.split()
        if len(palabras) > 0:
            sql_keywords = """
                SELECT id, pregunta, respuesta, categoria, palabras_clave
                FROM preguntas 
                WHERE activo = 'si'
            """
            cursor.execute(sql_keywords)
            todas_preguntas = cursor.fetchall()
            
            mejor_coincidencia = None
            mejor_puntaje = 0
            
            for pregunta_bd in todas_preguntas:
                pregunta_texto = pregunta_bd['pregunta'].lower()
                palabras_clave = pregunta_bd.get('palabras_clave', '').lower()
                
                # Calcular puntaje de coincidencia
                puntaje = 0
                for palabra in palabras:
                    if palabra in pregunta_texto:
                        puntaje += 3
                    if palabra in palabras_clave:
                        puntaje += 2
                
                if puntaje > mejor_puntaje:
                    mejor_puntaje = puntaje
                    mejor_coincidencia = pregunta_bd
            
            # Si hay una coincidencia razonable (al menos 2 puntos)
            if mejor_coincidencia and mejor_puntaje >= 2:
                logger.info(f"✅ Respuesta encontrada por palabras clave - Puntaje: {mejor_puntaje}")
                return mejor_coincidencia
        
        logger.info("ℹ️ No se encontró respuesta en FAQ")
        return None

    except mysql.connector.Error as e:
        logger.error(f"❌ Database error searching FAQ: {e}")
        return None
    except Exception as e:
        logger.error(f"❌ Unexpected error searching FAQ: {e}")
        return None
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

def guardar_consulta_faq(numero, pregunta_usuario, fue_util=None):
    """Guarda una consulta en el historial de consultas"""
    conn = None
    max_intentos = 2
    intento = 0
    
    while intento < max_intentos:
        try:
            conn = get_connection()
            if not conn:
                logger.error("❌ No se pudo conectar a la base de datos")
                return None
                
            cursor = conn.cursor()
            
            # Buscar respuesta en FAQ
            respuesta_faq = buscar_respuesta_inteligente(pregunta_usuario)
            
            # Extraer datos de la respuesta FAQ
            pregunta_faq_id = respuesta_faq['id'] if respuesta_faq else None
            respuesta_texto = respuesta_faq['respuesta'] if respuesta_faq else None
            categoria_faq = respuesta_faq['categoria'] if respuesta_faq else "General"
            
            # Insertar en HISTORIAL_CONSULTAS
            sql_consulta = """
                INSERT INTO historial_consultas 
                (numero, pregunta_usuario, pregunta_faq_id, respuesta_faq, categoria, fecha_consulta, fue_util)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """
            
            valores_consulta = (
                numero, 
                pregunta_usuario, 
                pregunta_faq_id, 
                respuesta_texto, 
                categoria_faq, 
                datetime.now(), 
                fue_util
            )
            
            cursor.execute(sql_consulta, valores_consulta)
            consulta_id = cursor.lastrowid
            
            conn.commit()
            
            logger.info(f"✅ Consulta guardada en historial - ID: {consulta_id}")
            logger.info(f"   📞 Número: {numero}")
            logger.info(f"   ❓ Pregunta: {pregunta_usuario}")
            logger.info(f"   🔗 FAQ ID: {pregunta_faq_id}")
            logger.info(f"   📊 Categoría: {categoria_faq}")
            logger.info(f"   👍 Útil: {fue_util}")
            
            return {
                'consulta_id': consulta_id,
                'pregunta_faq_id': pregunta_faq_id,
                'respuesta_faq': respuesta_texto,
                'categoria': categoria_faq,
                'fue_util': fue_util
            }
            
        except mysql.connector.Error as e:
            intento += 1
            logger.warning(f"⚠️ Intento {intento}/{max_intentos} - Error guardando consulta: {e}")
            
            if conn:
                conn.rollback()
                
            if intento < max_intentos:
                time.sleep(1)
            else:
                logger.error(f"❌ Error después de {max_intentos} intentos guardando consulta: {e}")
                return None
        except Exception as e:
            logger.error(f"❌ Unexpected error guardando consulta: {e}")
            if conn:
                conn.rollback()
            return None
        finally:
            if conn and conn.is_connected():
                cursor.close()
                conn.close()

def actualizar_feedback_consulta(consulta_id, fue_util):
    """Actualiza el feedback de una consulta en el historial y crea ticket si no fue útil"""
    conn = None
    max_intentos = 3
    intento = 0
    
    while intento < max_intentos:
        try:
            conn = get_connection()
            if not conn:
                logger.error("❌ No se pudo conectar a la base de datos")
                return False
                
            cursor = conn.cursor(dictionary=True)

            # Primero obtener los datos de la consulta
            sql_select = """
                SELECT numero, pregunta_usuario, categoria, respuesta_faq
                FROM historial_consultas 
                WHERE id = %s
            """
            cursor.execute(sql_select, (consulta_id,))
            consulta = cursor.fetchone()
            
            if not consulta:
                logger.error(f"❌ Consulta no encontrada: {consulta_id}")
                return False

            # Actualizar el feedback
            sql_update = """
                UPDATE historial_consultas 
                SET fue_util = %s
                WHERE id = %s
            """
            cursor.execute(sql_update, (fue_util, consulta_id))
            
            ticket_id = None
            
            # ✅ CREAR TICKET AUTOMÁTICAMENTE si la respuesta no fue útil
            if fue_util == 'no':
                logger.info(f"🔄 Creando ticket automático para consulta no útil: {consulta_id}")
                
                # Crear necesidad específica como solicitaste
                necesidad_ticket = f'Creacion de nuevas preguntas favor de contactar a "{consulta["numero"]}"'
                
                # Crear el ticket con la necesidad específica
                ticket_id = guardar_ticket(
                    numero=consulta['numero'],
                    necesidad=necesidad_ticket,
                    urgencia="urg_media",  # Urgencia por defecto
                    departamento="dep_it",  # Departamento por defecto
                    solucion_ai=f"El usuario indicó que la respuesta FAQ no fue útil para: '{consulta['pregunta_usuario']}'. Respuesta proporcionada: {consulta['respuesta_faq']}",
                    tipo="ticket_faq_no_util",
                    consulta_relacionada_id=consulta_id
                )
                
                if ticket_id:
                    # Actualizar la consulta con el ticket relacionado
                    sql_ticket = """
                        UPDATE historial_consultas 
                        SET ticket_relacionado_id = %s
                        WHERE id = %s
                    """
                    cursor.execute(sql_ticket, (ticket_id, consulta_id))
                    logger.info(f"✅ Ticket {ticket_id} creado y relacionado con consulta {consulta_id}")
                    logger.info(f"📝 Necesidad del ticket: {necesidad_ticket}")
                else:
                    logger.error(f"❌ Error al crear ticket para consulta {consulta_id}")
            
            conn.commit()
            
            logger.info(f"✅ Feedback actualizado - Consulta ID: {consulta_id}, Útil: {fue_util}")
            if ticket_id:
                logger.info(f"🎫 Ticket creado automáticamente - ID: {ticket_id}")
            return True

        except mysql.connector.Error as e:
            intento += 1
            logger.warning(f"⚠️ Intento {intento}/{max_intentos} - Error BD: {e}")
            
            if conn:
                conn.rollback()
                
            if intento < max_intentos:
                logger.info(f"🔄 Reintentando conexión en 2 segundos...")
                time.sleep(2)
            else:
                logger.error(f"❌ Error después de {max_intentos} intentos: {e}")
                return False
        finally:
            if conn and conn.is_connected():
                cursor.close()
                conn.close()
    
    return False

def obtener_historial_consultas(numero, limite=10):
    """Obtiene el historial de consultas de un usuario"""
    conn = None
    try:
        conn = get_connection()
        if not conn:
            logger.error("❌ No se pudo conectar a la base de datos")
            return []
            
        cursor = conn.cursor(dictionary=True)

        sql = """
            SELECT 
                hc.id,
                hc.pregunta_usuario,
                hc.respuesta_faq,
                hc.categoria,
                hc.fue_util,
                hc.fecha_consulta,
                hc.ticket_relacionado_id,
                p.pregunta as pregunta_faq
            FROM historial_consultas hc
            LEFT JOIN preguntas p ON hc.pregunta_faq_id = p.id
            WHERE hc.numero = %s
            ORDER BY hc.fecha_consulta DESC
            LIMIT %s
        """
        cursor.execute(sql, (numero, limite))
        
        consultas = cursor.fetchall()
        logger.info(f"✅ Obtenidas {len(consultas)} consultas para número: {numero}")
        return consultas

    except mysql.connector.Error as e:
        logger.error(f"❌ Database error getting consult history: {e}")
        return []
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

def verificar_consulta_guardada(consulta_id):
    """Verifica que una consulta se guardó correctamente"""
    conn = None
    try:
        conn = get_connection()
        if not conn:
            logger.error("❌ No se pudo conectar a la base de datos")
            return False
            
        cursor = conn.cursor(dictionary=True)

        sql = """
            SELECT 
                id, numero, pregunta_usuario, pregunta_faq_id, 
                respuesta_faq, categoria, fue_util, fecha_consulta,
                ticket_relacionado_id
            FROM historial_consultas 
            WHERE id = %s
        """
        cursor.execute(sql, (consulta_id,))
        
        consulta = cursor.fetchone()
        
        if consulta:
            logger.info(f"✅ Consulta verificada - ID: {consulta['id']}")
            logger.info(f"   📞 Número: {consulta['numero']}")
            logger.info(f"   ❓ Pregunta: {consulta['pregunta_usuario']}")
            logger.info(f"   🔗 FAQ ID: {consulta['pregunta_faq_id']}")
            logger.info(f"   📊 Categoría: {consulta['categoria']}")
            logger.info(f"   👍 Útil: {consulta['fue_util']}")
            logger.info(f"   🎫 Ticket Relacionado: {consulta['ticket_relacionado_id']}")
            logger.info(f"   📅 Fecha: {consulta['fecha_consulta']}")
            return True
        else:
            logger.error(f"❌ Consulta NO encontrada - ID: {consulta_id}")
            return False

    except mysql.connector.Error as e:
        logger.error(f"❌ Database error verifying consult: {e}")
        return False
    except Exception as e:
        logger.error(f"❌ Unexpected error verifying consult: {e}")
        return False
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

def obtener_ticket_por_consulta(consulta_id):
    """Obtiene el ticket relacionado con una consulta FAQ"""
    conn = None
    try:
        conn = get_connection()
        if not conn:
            logger.error("❌ No se pudo conectar a la base de datos")
            return None
            
        cursor = conn.cursor(dictionary=True)

        sql = """
            SELECT ticket_relacionado_id 
            FROM historial_consultas 
            WHERE id = %s
        """
        cursor.execute(sql, (consulta_id,))
        
        resultado = cursor.fetchone()
        return resultado['ticket_relacionado_id'] if resultado else None

    except mysql.connector.Error as e:
        logger.error(f"❌ Database error getting related ticket: {e}")
        return None
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

# FUNCIONES DE USUARIO Y TÉCNICOS
def verificar_permiso_usuario(numero):
    """Verifica si el número tiene permiso para usar el chatbot"""
    conn = None
    try:
        conn = get_connection()
        if not conn:
            logger.error("❌ No se pudo conectar a la base de datos")
            return False
            
        cursor = conn.cursor()

        sql = "SELECT COUNT(*) FROM usuarios WHERE numero = %s AND activo = 'si'"
        cursor.execute(sql, (numero,))
        
        resultado = cursor.fetchone()
        tiene_permiso = resultado[0] > 0 if resultado else False
        
        if tiene_permiso:
            logger.info(f"✅ Usuario autorizado: {numero}")
        else:
            logger.warning(f"⛔ Usuario NO autorizado: {numero}")
            
        return tiene_permiso

    except mysql.connector.Error as e:
        logger.error(f"❌ Database error checking permission: {e}")
        return False
    except Exception as e:
        logger.error(f"❌ Unexpected error checking permission: {e}")
        return False
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

def guardar_ticket(numero, necesidad, urgencia, departamento, solucion_ai=None, tipo="ticket", consulta_relacionada_id=None):
    """Guarda un ticket en la base de datos con manejo de reconexión"""
    max_intentos = 2
    intento = 0
    
    while intento < max_intentos:
        conn = None
        try:
            conn = get_connection()
            if not conn:
                logger.error("❌ No se pudo conectar a la base de datos")
                return None
                
            cursor = conn.cursor()

            sql = """
                INSERT INTO tickets 
                (numero, necesidad, urgencia, departamento, solucion_ai, fecha_creacion, estado, tipo, consulta_relacionada_id)
                VALUES (%s, %s, %s, %s, %s, %s, 'abierto', %s, %s)
            """
            values = (numero, necesidad, urgencia, departamento, solucion_ai, datetime.now(), tipo, consulta_relacionada_id)

            cursor.execute(sql, values)
            ticket_id = cursor.lastrowid
            conn.commit()
            
            logger.info(f"✅ Ticket guardado en BD - ID: {ticket_id}, Número: {numero}, Tipo: {tipo}")
            return ticket_id

        except mysql.connector.Error as e:
            intento += 1
            logger.warning(f"⚠️ Intento {intento}/{max_intentos} - Error guardando ticket: {e}")
            
            if conn:
                conn.rollback()
                
            if intento < max_intentos:
                time.sleep(1)
            else:
                logger.error(f"❌ Error después de {max_intentos} intentos guardando ticket: {e}")
                return None
        except Exception as e:
            logger.error(f"❌ Unexpected error saving ticket: {e}")
            if conn:
                conn.rollback()
            return None
        finally:
            if conn and conn.is_connected():
                cursor.close()
                conn.close()
    
    return None

def actualizar_evaluacion_tecnico(ticket_id, evaluacion):
    """Actualiza la evaluación del técnico (si/no) en la columna tecnico_util"""
    conn = None
    max_intentos = 2
    intento = 0
    
    while intento < max_intentos:
        try:
            conn = get_connection()
            if not conn:
                logger.error("❌ No se pudo conectar a la base de datos")
                return False
                
            cursor = conn.cursor()

            sql = """
                UPDATE tickets 
                SET tecnico_util = %s
                WHERE id = %s
            """
            values = (evaluacion, ticket_id)

            cursor.execute(sql, values)
            conn.commit()
            
            logger.info(f"✅ Evaluación actualizada - Ticket ID: {ticket_id}, Evaluación: {evaluacion}")
            return True

        except mysql.connector.Error as e:
            intento += 1
            logger.warning(f"⚠️ Intento {intento}/{max_intentos} - Error actualizando evaluación: {e}")
            
            if conn:
                conn.rollback()
                
            if intento < max_intentos:
                time.sleep(1)
            else:
                logger.error(f"❌ Error después de {max_intentos} intentos actualizando evaluación: {e}")
                return False
        except Exception as e:
            logger.error(f"❌ Unexpected error updating evaluation: {e}")
            if conn:
                conn.rollback()
            return False
        finally:
            if conn and conn.is_connected():
                cursor.close()
                conn.close()
    
    return False

def obtener_tecnicos_activos():
    """Obtiene todos los técnicos activos"""
    conn = None
    try:
        conn = get_connection()
        if not conn:
            logger.error("❌ No se pudo conectar a la base de datos")
            return []
            
        cursor = conn.cursor(dictionary=True)

        sql = """
            SELECT id, numero, nombre, email, departamento, telefono
            FROM tecnicos 
            WHERE activo = 'si'
            ORDER BY nombre
        """
        cursor.execute(sql)
        
        tecnicos = cursor.fetchall()
        logger.info(f"✅ Obtenidos {len(tecnicos)} técnicos activos")
        return tecnicos

    except mysql.connector.Error as e:
        logger.error(f"❌ Database error getting technicians: {e}")
        return []
    except Exception as e:
        logger.error(f"❌ Unexpected error getting technicians: {e}")
        return []
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

def obtener_tecnico_por_departamento(departamento):
    """Obtiene técnicos por departamento"""
    conn = None
    try:
        conn = get_connection()
        if not conn:
            logger.error("❌ No se pudo conectar a la base de datos")
            return []
            
        cursor = conn.cursor(dictionary=True)

        sql = """
            SELECT id, numero, nombre, email, telefono
            FROM tecnicos 
            WHERE departamento = %s AND activo = 'si'
            ORDER BY nombre
        """
        cursor.execute(sql, (departamento,))
        
        tecnicos = cursor.fetchall()
        logger.info(f"✅ Obtenidos {len(tecnicos)} técnicos para departamento: {departamento}")
        return tecnicos

    except mysql.connector.Error as e:
        logger.error(f"❌ Database error getting technicians by department: {e}")
        return []
    except Exception as e:
        logger.error(f"❌ Unexpected error getting technicians by department: {e}")
        return []
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

def asignar_tecnico_ticket(ticket_id, tecnico_id):
    """Asigna un técnico a un ticket"""
    conn = None
    max_intentos = 2
    intento = 0
    
    while intento < max_intentos:
        try:
            conn = get_connection()
            if not conn:
                logger.error("❌ No se pudo conectar a la base de datos")
                return False
                
            cursor = conn.cursor()

            # Verificar si la tabla tickets tiene columna para técnico
            cursor.execute("SHOW COLUMNS FROM tickets LIKE 'tecnico_id'")
            tiene_columna = cursor.fetchone() is not None

            if not tiene_columna:
                # Agregar columna si no existe
                cursor.execute("ALTER TABLE tickets ADD COLUMN tecnico_id INT(11) NULL")
                logger.info("✅ Columna tecnico_id agregada a tabla tickets")

            sql = "UPDATE tickets SET tecnico_id = %s WHERE id = %s"
            cursor.execute(sql, (tecnico_id, ticket_id))
            conn.commit()
            
            logger.info(f"✅ Técnico {tecnico_id} asignado al ticket {ticket_id}")
            return True

        except mysql.connector.Error as e:
            intento += 1
            logger.warning(f"⚠️ Intento {intento}/{max_intentos} - Error asignando técnico: {e}")
            
            if conn:
                conn.rollback()
                
            if intento < max_intentos:
                time.sleep(1)
            else:
                logger.error(f"❌ Error después de {max_intentos} intentos asignando técnico: {e}")
                return False
        except Exception as e:
            logger.error(f"❌ Unexpected error assigning technician: {e}")
            if conn:
                conn.rollback()
            return False
        finally:
            if conn and conn.is_connected():
                cursor.close()
                conn.close()
    
    return False

# FUNCIONES DE USO PRÁCTICO
def procesar_consulta_usuario(numero, pregunta_usuario):
    """
    Flujo completo para procesar una consulta de usuario
    """
    logger.info(f"🎯 Procesando consulta - Número: {numero}, Pregunta: {pregunta_usuario}")
    
    # Guardar la consulta FAQ
    resultado = guardar_consulta_faq(numero, pregunta_usuario)
    
    if resultado and resultado['consulta_id']:
        # Verificar que se guardó correctamente
        verificar_consulta_guardada(resultado['consulta_id'])
        
        return resultado
    else:
        logger.error("❌ Error al procesar la consulta")
        return None

def dar_feedback_consulta(consulta_id, fue_util):
    """
    Permite al usuario dar feedback sobre una consulta
    """
    logger.info(f"📊 Registrando feedback - Consulta: {consulta_id}, Útil: {fue_util}")
    
    if actualizar_feedback_consulta(consulta_id, fue_util):
        logger.info(f"✅ Feedback registrado correctamente")
        return True
    else:
        logger.error(f"❌ Error al registrar feedback")
        return False

def diagnosticar_tablas():
    """Función para diagnosticar la estructura de la BD"""
    conn = None
    try:
        conn = get_connection()
        if not conn:
            logger.error("❌ No se pudo conectar a la base de datos")
            return
            
        cursor = conn.cursor()
        
        # Ver todas las tablas
        cursor.execute("SHOW TABLES")
        tablas = cursor.fetchall()
        logger.info(f"📊 Tablas en la base de datos: {[tabla[0] for tabla in tablas]}")
        
        # Ver estructura de cada tabla relevante
        tablas_relevantes = ['preguntas', 'historial_consultas', 'tickets', 'usuarios', 'tecnicos']
        for tabla in tablas_relevantes:
            try:
                cursor.execute(f"DESCRIBE {tabla}")
                columnas = cursor.fetchall()
                logger.info(f"📋 Estructura de {tabla}: {[columna[0] for columna in columnas]}")
            except Exception as e:
                logger.info(f"❌ La tabla {tabla} no existe o no se puede acceder: {e}")
        
        # Ver datos de ejemplo en preguntas
        try:
            cursor.execute("SELECT id, pregunta, respuesta, categoria FROM preguntas WHERE activo = 'si' LIMIT 3")
            datos = cursor.fetchall()
            logger.info(f"📝 Datos de ejemplo en preguntas: {datos}")
        except Exception as e:
            logger.info(f"❌ No se pudo leer datos de preguntas: {e}")
            
    except Exception as e:
        logger.error(f"❌ Error en diagnóstico: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

# EJEMPLOS DE USO
if __name__ == "__main__":
    # Verificar estado de la BD al inicio
    logger.info("🔍 Verificando estado de base de datos...")
    if verificar_estado_bd():
        logger.info("✅ Base de datos conectada correctamente")
    else:
        logger.error("❌ Problemas con la base de datos")
    
    # Ejemplo 1: Consulta normal
    resultado = procesar_consulta_usuario(
        numero="123456789",
        pregunta_usuario="¿Cómo cambio mi contraseña?"
    )
    
    # Ejemplo 2: Dar feedback después
    if resultado:
        dar_feedback_consulta(resultado['consulta_id'], True)
    
    # Ejemplo 3: Obtener historial
    historial = obtener_historial_consultas("123456789", 5)
    for consulta in historial:
        print(f"Consulta: {consulta['pregunta_usuario']} - Respuesta: {consulta['respuesta_faq']}")