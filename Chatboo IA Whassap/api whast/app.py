from flask import Flask, request
import util
import Whatsappservice 
import logging
from datetime import datetime
import database
import requests
import json
import os
import re

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# ✅ CONFIGURACIÓN PARA GPT-5 MINI (API PERSONALIZADA)
try:
    # API Key para GPT-5 mini - desde variable de entorno
    api_key = os.getenv("GPT5_MINI_API_KEY", "TokenIA")
    
    # URL base del servicio GPT-5 mini (ajusta según tu proveedor)
    api_base = os.getenv("GPT5_MINI_API_BASE", "https://api.openai.com/v1")
    
    logger.info(f"🔍 Configurando GPT-5 mini...")
    logger.info(f"📏 Longitud API Key: {len(api_key)} caracteres")
    logger.info(f"🌐 API Base: {api_base}")
    
    if not api_key or api_key == "tu_api_key_aqui":
        logger.error("❌ API key no configurada")
        ai_client = None
    else:
        logger.info(f"✅ GPT-5 mini configurado - Inicia con: {api_key[:10]}...")
        ai_client = {
            "api_key": api_key,
            "api_base": api_base,
            "model": "gpt-3.5-turbo"  # Mantenemos el nombre original
        }
        
except Exception as e:
    logger.error(f"❌ ERROR configurando GPT-5 mini: {e}")
    ai_client = None

app = Flask(__name__)

# Diccionario para guardar sesión de cada usuario
user_sessions = {}

def create_chat_payload(messages, max_tokens=200):
    """
    Crea payload compatible con el modelo específico
    """
    payload = {
        "model": ai_client["model"],
        "messages": messages,
        "max_completion_tokens": max_tokens
    }
    return payload

@app.route('/welcome', methods=['GET'])
def index():
    return "🚀 Bot de WhatsApp con GPT-5 Mini - Funcionando Correctamente"

@app.route('/whatsapp', methods=['GET'])
def VerifyToken():
    try:
        accessToken = "Dumas007"
        token = request.args.get("hub.verify_token")
        challenge = request.args.get("hub.challenge")

        logger.info(f"🔍 Webhook verification - Token: {token}, Challenge: {challenge}")

        if token and challenge and token == accessToken:
            logger.info("✅ Webhook verification SUCCESS")
            return challenge
        else:
            logger.warning("❌ Webhook verification FAILED")
            return "Verification failed", 400
    except Exception as e:
        logger.error(f"❌ Webhook verification ERROR: {e}")
        return "Error", 400

@app.route('/test-ai', methods=['GET'])
def test_ai():
    """Ruta para probar la conexión con GPT-5 Mini"""
    try:
        if ai_client is None:
            return "❌ GPT-5 Mini no configurado - Revisa los logs y tu API Key"
        
        headers = {
            "Authorization": f"Bearer {ai_client['api_key']}",
            "Content-Type": "application/json"
        }
        
        payload = create_chat_payload(
            messages=[
                {"role": "user", "content": "Responde solo con '✅ GPT-5 MINI FUNCIONA'"}
            ],
            max_tokens=10
        )
        
        logger.info(f"🧪 Probando GPT-5 Mini...")
        response = requests.post(
            f"{ai_client['api_base']}/chat/completions",
            headers=headers,
            json=payload,
            timeout=30
        )
        
        if response.status_code == 200:
            result = response.json()
            message_content = result["choices"][0]["message"]["content"]
            return f"✅ GPT-5 Mini funciona: '{message_content}'"
        else:
            return f"❌ Error en GPT-5 Mini: {response.status_code} - {response.text}"
        
    except Exception as e:
        logger.error(f"❌ Error en test-ai: {e}")
        return f"❌ Error en test: {str(e)}"

@app.route('/whatsapp', methods=['POST'])
def recibirMensaje():
    try:
        body = request.get_json()
        
        if not body or "entry" not in body or not body["entry"]:
            logger.warning("❌ Invalid webhook structure")
            return "EVENT_RECEIVED"
            
        entry = body["entry"][0]
        
        if "changes" not in entry or not entry["changes"]:
            logger.warning("❌ No changes in entry")
            return "EVENT_RECEIVED"
            
        changes = entry["changes"][0]
        value = changes["value"]
        
        if "messages" not in value or not value["messages"]:
            logger.warning("❌ No messages in value")
            return "EVENT_RECEIVED"
            
        message = value["messages"][0]
        
        if "from" not in message:
            logger.warning("❌ No sender in message")
            return "EVENT_RECEIVED"
            
        number = message["from"]
        
        if not number or len(number) < 10:
            logger.warning(f"❌ Invalid phone number: {number}")
            return "EVENT_RECEIVED"

        if not database.verificar_permiso_usuario(number):
            logger.warning(f"⛔ Usuario sin permisos: {number}")
            data = util.MensajeSinPermiso(number)
            Whatsappservice.sendMessageWhatsapp(data)
            return "EVENT_RECEIVED"

        text = ""
        if "interactive" in message:
            interactive_type = message["interactive"]["type"]
            if interactive_type == "button_reply":
                text = message["interactive"]["button_reply"]["id"]
                logger.info(f"🔘 Mensaje interactivo (botón): {text}")
            elif interactive_type == "list_reply":
                text = message["interactive"]["list_reply"]["id"]
                logger.info(f"📋 Mensaje interactivo (lista): {text}")
        elif "text" in message:
            text = message["text"]["body"]
            logger.info(f"📝 Mensaje de texto: {text}")
        else:
            logger.warning(f"⚠️ Tipo de mensaje no soportado: {message.get('type', 'desconocido')}")
            data = util.TextMessage("⚠️ Solo se admiten mensajes de texto o interactivos.", number)
            Whatsappservice.sendMessageWhatsapp(data)
            return "EVENT_RECEIVED"

        text_clean = text.strip()
        logger.info(f"📩 Message from {number}: {text_clean}")

        GenerateMessage(text_clean, number)
        return "EVENT_RECEIVED"

    except Exception as e:
        logger.error(f"❌ Error processing message: {e}")
        return "EVENT_RECEIVED"

def consultar_gpt5_mini(necesidad, departamento):
    """
    Consulta a GPT-5 Mini con la necesidad del usuario
    """
    try:
        if ai_client is None:
            logger.error("❌ GPT-5 Mini no está configurado")
            return "🔧 Nuestro sistema de IA no está disponible. Hemos registrado tu ticket y un asesor te contactará pronto."
            
        logger.info(f"🔍 Consultando GPT-5 Mini - Departamento: {departamento}")
        logger.info(f"📝 Necesidad a analizar: {necesidad}")
        
        prompt = f"""
        Problema: "{necesidad}"
        Área: {departamento}

        Como asistente técnico, proporciona:

        🔍 **Análisis**: Posible causa del problema
        🛠️ **Soluciones**: 2-3 pasos prácticos para resolver
        💡 **Siguientes pasos**: Recomendación si no funciona

        **Formato**: 
        - Español claro y conciso
        - Máximo 150 caracteres
        - Usa emojis relevantes
        - Enfocado en solución práctica
        """

        logger.info(f"📋 Enviando prompt a GPT-5 Mini...")
        
        headers = {
            "Authorization": f"Bearer {ai_client['api_key']}",
            "Content-Type": "application/json"
        }
        
        payload = create_chat_payload(
            messages=[
                {
                    "role": "system", 
                    "content": "Eres un asistente técnico especializado. Responde de forma CONCRETA, ÚTIL y en español. Siempre da soluciones prácticas. Usa emojis para WhatsApp. Sé breve (máx 150 caracteres)."
                },
                {"role": "user", "content": prompt}
            ],
            max_tokens=200
        )
        
        try:
            response = requests.post(
                f"{ai_client['api_base']}/chat/completions",
                headers=headers,
                json=payload,
                timeout=30
            )
            
            if response.status_code == 200:
                result = response.json()
                respuesta = result["choices"][0]["message"]["content"].strip()
                
                if not respuesta or len(respuesta) < 10:
                    logger.warning(f"⚠️ GPT-5 Mini respondió con texto vacío: '{respuesta}'")
                    return "🔍 Analizando tu caso... Un especialista te dará una solución pronto. 📋"
                
                logger.info(f"✅ GPT-5 Mini respondió exitosamente")
                logger.info(f"📄 Respuesta: {respuesta}")
                logger.info(f"📊 Longitud: {len(respuesta)} caracteres")
                return respuesta
                
            else:
                logger.error(f"❌ Error API GPT-5 Mini: {response.status_code} - {response.text}")
                return "🔧 Nuestro sistema de IA está ocupado. Hemos registrado tu ticket y un asesor te contactará pronto. ✅"
                
        except requests.exceptions.Timeout:
            logger.error("❌ Timeout en GPT-5 Mini")
            return "⏰ El análisis está tomando más tiempo. Hemos registrado tu ticket y te contactaremos pronto. ✅"
        except requests.exceptions.ConnectionError:
            logger.error("❌ Error de conexión con GPT-5 Mini")
            return "🔌 Problema de conexión. Hemos registrado tu ticket manualmente. ✅"
            
    except Exception as e:
        logger.error(f"❌ Error inesperado en consultar_gpt5_mini: {e}")
        return "⚠️ Hemos registrado tu ticket. Un asesor humano te contactará pronto para ayudarte. ✅"

def GenerateMessage(text, number):
    """
    Función principal para generar respuestas
    """
    if not text or len(text) > 1000:
        data = util.TextMessage("⚠️ El mensaje es demasiado largo o vacío. Por favor, escribe un mensaje más breve.", number)
        Whatsappservice.sendMessageWhatsapp(data)
        return

    text = text.strip().lower()
    logger.info(f"🔄 Procesando mensaje para {number}: '{text}'")
    
    if number not in user_sessions:
        user_sessions[number] = {
            "esperando_reinicio": False, 
            "esperando_evaluacion": False,
            "esperando_feedback_faq": False,
            "modo_consulta": False,
            "created_at": datetime.now(),
            "step": "inicio"
        }
        logger.info(f"🆕 Nueva sesión creada para {number}")

    session = user_sessions[number]
    
    cleanup_old_sessions()

    # --- 1. PRIMERO: Procesar feedback de FAQ ---
    if session.get("esperando_feedback_faq", False):
        if text in ['si', 'no']:
            # ✅ ACTUALIZAR EL FEEDBACK EN LA BASE DE DATOS
            consulta_id = session.get('consulta_actual_id')
            if consulta_id:
                fue_util_bd = 'si' if text == 'si' else 'no'
                
                # ✅ SI NO FUE ÚTIL, CREAR TICKET PRIMERO Y LUEGO ACTUALIZAR FEEDBACK
                if fue_util_bd == 'no':
                    try:
                        # Primero crear el ticket
                        logger.info(f"🔄 Creando ticket para consulta no útil: {consulta_id}")
                        
                        # Obtener datos de la consulta
                        conn = database.get_connection()
                        ticket_id = None
                        
                        if conn:
                            cursor = conn.cursor(dictionary=True)
                            cursor.execute("SELECT numero, pregunta_usuario, respuesta_faq FROM historial_consultas WHERE id = %s", (consulta_id,))
                            consulta = cursor.fetchone()
                            
                            if consulta:
                                # Crear necesidad específica
                                necesidad_ticket = f'Creacion de nuevas preguntas favor de contactar a "{consulta["numero"]}"'
                                
                                # ✅ CREAR SOLO UN TICKET - usar la función directamente
                                ticket_id = database.guardar_ticket(
                                    numero=consulta['numero'],
                                    necesidad=necesidad_ticket,
                                    urgencia="urg_media",
                                    departamento="dep_it",
                                    solucion_ai=f"El usuario indicó que la respuesta FAQ no fue útil para: '{consulta['pregunta_usuario']}'",
                                    tipo="ticket_faq_no_util",
                                    consulta_relacionada_id=consulta_id
                                )
                                
                                if ticket_id:
                                    # ✅ ACTUALIZAR LA CONSULTA CON EL TICKET RELACIONADO
                                    cursor.execute(
                                        "UPDATE historial_consultas SET ticket_relacionado_id = %s WHERE id = %s", 
                                        (ticket_id, consulta_id)
                                    )
                                    conn.commit()
                                    logger.info(f"✅ Ticket {ticket_id} relacionado con consulta {consulta_id}")
                            
                            cursor.close()
                            conn.close()
                        
                        # ✅ MOSTRAR MENSAJE AL USUARIO
                        if ticket_id:
                            data = util.TextMessage(
                                "⚠️ Lamento que la respuesta no fuera útil.\n\n"
                                f"✅ *Hemos creado un ticket* (#{ticket_id}) para que nuestro equipo te ayude personalmente.\n\n"
                                "📞 Te contactaremos en breve.\n\n"
                                "Si quieres iniciar otra vez, escribe *hola*.",
                                number
                            )
                            logger.info(f"🎫 Ticket #{ticket_id} creado exitosamente")
                        else:
                            data = util.TextMessage(
                                "⚠️ Lamento que la respuesta no fuera útil.\n\n"
                                "✅ *Hemos registrado tu caso* para que nuestro equipo te ayude personalmente.\n\n"
                                "📞 Te contactaremos en breve.\n\n"
                                "Si quieres iniciar otra vez, escribe *hola*.",
                                number
                            )
                            
                    except Exception as e:
                        logger.error(f"❌ Error creando ticket: {e}")
                        data = util.TextMessage(
                            "⚠️ Lamento que la respuesta no fuera útil.\n\n"
                            "✅ *Hemos registrado tu caso* para atención personalizada.\n\n"
                            "📞 Te contactaremos en breve.\n\n"
                            "Si quieres iniciar otra vez, escribe *hola*.",
                            number
                        )
                else:
                    # Para feedback "sí", solo actualizar feedback
                    try:
                        database.actualizar_feedback_consulta(consulta_id, 'si')
                    except:
                        logger.warning("⚠️ No se pudo actualizar feedback positivo")
                    
                    data = util.TextMessage(
                        "🎉 ¡Excelente! Me alegra haber podido ayudarte.\n\n"
                        "No dudes en contactarnos nuevamente escribiendo *hola*.\n\n"
                        "¡Estamos aquí para ayudarte! 👋", 
                        number
                    )
            else:
                data = util.TextMessage("❌ No se encontró la consulta. Por favor, intenta nuevamente.", number)
            
            Whatsappservice.sendMessageWhatsapp(data)
            session['esperando_feedback_faq'] = False
            session.pop('consulta_actual_id', None)
            
            # ✅ ESTABLECER SESIÓN EN MODO ESPERANDO REINICIO
            session['esperando_reinicio'] = True
            session['step'] = "esperando_hola"
            return

    # --- 2. SEGUNDO: Procesar oferta de ticket después de FAQ no útil ---
    if session.get("step") == "ofrecer_ticket":
        if text == 'si':
            session.clear()
            session.update({
                "created_at": datetime.now(),
                "step": "menu_principal"
            })
            data = util.MenuMessage(number)
            Whatsappservice.sendMessageWhatsapp(data)
            return
        elif text == 'no':
            data = util.TextMessage("✅ Entendido. ¿Tienes alguna otra pregunta? Estoy aquí para ayudarte.", number)
            Whatsappservice.sendMessageWhatsapp(data)
            session['step'] = "esperando_pregunta"
            return
        else:
            data = util.TextMessage("🤔 Por favor, responde *si* o *no* si deseas crear un ticket para atención personalizada.", number)
            Whatsappservice.sendMessageWhatsapp(data)
            return

    # --- 3. TERCERO: Si está en opciones después de no encontrar respuesta ---
    if session.get("step") == "opciones_despues_sin_respuesta":
        if text == 'hola':
            session.clear()
            session.update({
                "created_at": datetime.now(),
                "step": "menu_principal"
            })
            data = util.MenuMessage(number)
            Whatsappservice.sendMessageWhatsapp(data)
            return
        else:
            session['step'] = "esperando_pregunta"
            data = util.TextMessage("🔍 Por favor, escribe tu pregunta y buscaré en nuestra base de conocimientos.", number)
            Whatsappservice.sendMessageWhatsapp(data)
            return

    # --- 4. CUARTO: Si está esperando evaluación ---
    if session.get("esperando_evaluacion", False):
        if text in ["eval_si", "eval_no"]:
            evaluacion = "si" if text == "eval_si" else "no"
            ticket_id = session.get("ticket_id")
            
            if ticket_id:
                if database.actualizar_evaluacion_tecnico(ticket_id, evaluacion):
                    logger.info(f"✅ Evaluación guardada - Ticket: {ticket_id}, Evaluación: {evaluacion}")
                else:
                    logger.error(f"❌ Error al guardar evaluación - Ticket: {ticket_id}")
            
            data = util.MensajeGracias(number)
            Whatsappservice.sendMessageWhatsapp(data)
            
            session.clear()
            session["esperando_reinicio"] = True
            session["step"] = "esperando_hola"
            session["created_at"] = datetime.now()
            
            data_final = util.TextMessage(
                "🔄 ¿Necesitas ayuda con algo más?\n\n"
                "Escribe *hola* para crear un nuevo ticket o consulta.", 
                number
            )
            Whatsappservice.sendMessageWhatsapp(data_final)
            return
        else:
            data = util.TextMessage("🤔 Por favor, selecciona una opción válida: ✅ Sí o ❌ No", number)
            Whatsappservice.sendMessageWhatsapp(data)
            return

    # --- 5. QUINTO: Si está esperando reinicio, solo acepta "hola" ---
    if session.get("esperando_reinicio", False) or session.get("step") == "esperando_hola":
        if text == "hola":
            session.clear()
            session.update({
                "esperando_reinicio": False, 
                "created_at": datetime.now(),
                "step": "inicio"
            })
            data = util.MenuMessage(number)
            logger.info(f"🔄 Sesión reiniciada para {number}")
            Whatsappservice.sendMessageWhatsapp(data)
            return
        else:
            data = util.TextMessage("✋ Para crear un nuevo ticket o consulta, escribe *hola*.", number)
            Whatsappservice.sendMessageWhatsapp(data)
            return

    # --- 6. SEXTO: Menú principal ---
    if text == "hola":
        session.clear()
        session.update({
            "created_at": datetime.now(),
            "step": "menu_principal"
        })
        data = util.MenuMessage(number)
        logger.info(f"👋 Menú principal enviado a {number}")
        Whatsappservice.sendMessageWhatsapp(data)
        return

    # --- 7. SÉPTIMO: Usuario selecciona Ticket/IT ---
    elif text == "main-ticket":
        session.clear()
        session.update({
            "created_at": datetime.now(),
            "step": "capturando_necesidad"
        })
        data = util.PreguntaNecesidad(number)
        logger.info(f"🎫 Flujo de ticket iniciado para {number}")
        Whatsappservice.sendMessageWhatsapp(data)
        return

    # --- 8. OCTAVO: Usuario selecciona Consulta General ---
    elif text == "main-consulta":
        session.clear()
        session.update({
            "modo_consulta": True,
            "step": "esperando_pregunta",
            "created_at": datetime.now()
        })
        data = util.TextMessage(
            "🔍 *Modo Consulta Inteligente Activado*\n\n"
            "Puedes hacerme cualquier pregunta y buscaré la respuesta en nuestra base de conocimientos.\n\n"
            "Ejemplos:\n"
            "• ¿Cómo restablezco mi contraseña?\n"
            "• ¿El sistema está caído?\n"
            "• ¿Cuáles son los horarios de atención?\n"
            "• ¿Cómo contacto al soporte?\n\n"
            "¡Escribe tu pregunta!",
            number
        )
        logger.info(f"🔍 Modo consulta activado para {number}")
        Whatsappservice.sendMessageWhatsapp(data)
        return

    # --- 9. NOVENO: Guardar Necesidad y preguntar Urgencia ---
    elif session.get("step") == "capturando_necesidad":
        if len(text) > 500:
            data = util.TextMessage("⚠️ La descripción es demasiado larga. Por favor, describe tu problema en menos de 500 caracteres.", number)
            logger.warning(f"📝 Necesidad demasiado larga de {number}: {len(text)} caracteres")
            Whatsappservice.sendMessageWhatsapp(data)
            return
        else:
            session["necesidad"] = text
            session["step"] = "capturando_urgencia"
            data = util.PreguntaUrgencia(number)
            logger.info(f"💾 Necesidad guardada para {number}: {text[:50]}...")
            Whatsappservice.sendMessageWhatsapp(data)
            return

    # --- 10. DÉCIMO: Guardar Urgencia y preguntar Departamento ---
    elif text in ["urg_baja", "urg_media", "urg_alta"] and session.get("step") == "capturando_urgencia":
        session["urgencia"] = text
        session["step"] = "capturando_departamento"
        data = util.PreguntaDepartamento(number)
        logger.info(f"⚡ Urgencia guardada para {number}: {text}")
        Whatsappservice.sendMessageWhatsapp(data)
        return

    # --- 11. ONCEAVO: Guardar Departamento, consultar GPT-5 Mini y mostrar resumen ---
    elif text.startswith("dep_") and session.get("step") == "capturando_departamento":
        valid_departments = ["dep_it", "dep_rrhh", "dep_finanzas", "dep_backoffice", 
                           "dep_marketing", "dep_ventas", "dep_otros"]
        
        if text not in valid_departments:
            data = util.TextMessage("⚠️ Departamento no válido. Por favor, selecciona una opción de la lista.", number)
            Whatsappservice.sendMessageWhatsapp(data)
            return
            
        session["departamento"] = text
        session["step"] = "procesando_con_ia"

        solucion_ai = None
        logger.info(f"🔄 INICIANDO CONSULTA GPT-5 MINI para {number}")

        try:
            departamento_map = {
                "dep_it": "IT/Sistemas",
                "dep_rrhh": "Recursos Humanos", 
                "dep_finanzas": "Finanzas",
                "dep_backoffice": "BackOffice",
                "dep_marketing": "Marketing",
                "dep_ventas": "Ventas",
                "dep_otros": "General"
            }
            
            departamento_nombre = departamento_map.get(text, "General")
            necesidad_texto = session.get('necesidad', '')
            
            logger.info(f"🎯 Consultando GPT-5 Mini - Depto: {departamento_nombre}")
            
            logger.info("🔍 Llamando a consultar_gpt5_mini...")
            solucion_ai = consultar_gpt5_mini(necesidad_texto, departamento_nombre)
            
            if solucion_ai and len(solucion_ai) > 20:
                logger.info(f"✅ GPT-5 Mini respondió exitosamente")
                logger.info(f"💡 Solución AI: {solucion_ai[:100]}...")
            else:
                logger.warning("⚠️ No se pudo obtener respuesta válida de GPT-5 Mini")
                solucion_ai = "🔍 *Análisis automático:*\nNuestro sistema está procesando tu caso. Un especialista te contactará pronto. 📋"
                
        except Exception as e:
            logger.error(f"❌ ERROR en proceso de IA: {e}")
            solucion_ai = "⚠️ *Información:*\nHemos registrado tu ticket. Un asesor te contactará pronto. ✅"

        try:
            logger.info(f"💾 Guardando en BD para {number}")
            ticket_id = database.guardar_ticket(
                numero=number,
                necesidad=session.get('necesidad', ''),
                urgencia=session.get('urgencia', ''),
                departamento=session.get('departamento', ''),
                solucion_ai=solucion_ai
            )
            
            if ticket_id:
                logger.info(f"✅ Ticket guardado en BD para {number} - ID: {ticket_id}")
                session["ticket_id"] = ticket_id
            else:
                logger.error(f"❌ Error al guardar ticket para {number}")
                data = util.TextMessage("⚠️ Error al guardar el ticket. Por favor, intenta nuevamente.", number)
                Whatsappservice.sendMessageWhatsapp(data)
                return
                
        except Exception as e:
            logger.error(f"❌ Error al guardar ticket en BD: {e}")
            data = util.TextMessage("⚠️ Error del sistema. Por favor, intenta más tarde.", number)
            Whatsappservice.sendMessageWhatsapp(data)
            return

        urgencia_map = {"urg_baja": "Baja", "urg_media": "Media", "urg_alta": "Alta"}
        departamento_map_display = {
            "dep_it": "IT / Sistemas", 
            "dep_rrhh": "Recursos Humanos", 
            "dep_finanzas": "Finanzas",
            "dep_backoffice": "BackOffice", 
            "dep_marketing": "Marketing", 
            "dep_ventas": "Ventas", 
            "dep_otros": "Otros"
        }

        resumen = (
            f"🎫 *Ticket Registrado con GPT-5 Mini*\n\n"
            f"📋 *Resumen:*\n"
            f"• ✏️ *Necesidad:* {session['necesidad']}\n"
            f"• ⚡ *Urgencia:* {urgencia_map.get(session['urgencia'], session['urgencia'])}\n"
            f"• 📂 *Departamento:* {departamento_map_display.get(session['departamento'], session['departamento'])}\n\n"
        )
        
        if solucion_ai and len(solucion_ai) > 20:
            resumen += f"🤖 *Análisis IA:*\n{solucion_ai}\n\n"
            resumen += f"✅ *Ticket analizado y registrado.* .\n\n"
        else:
            resumen += f"🔍 *Procesando con GPT-5 Mini:*\nTu caso está siendo analizado. Contactaremos pronto.\n\n"
            
        resumen += f"🔄 Escribe *hola* para nuevo ticket."

        logger.info(f"📤 Enviando mensaje final a {number}")
        data = util.TextMessage(resumen, number)
        Whatsappservice.sendMessageWhatsapp(data)

        logger.info(f"❓ Enviando pregunta de evaluación para ticket {ticket_id}")
        data_evaluacion = util.PreguntaEvaluacion(number)
        Whatsappservice.sendMessageWhatsapp(data_evaluacion)

        session["esperando_evaluacion"] = True
        session["step"] = "evaluando_tecnico"
        logger.info(f"🔚 Sesión en evaluación para {number}")

        return

    # --- 12. DOCEAVO: FINALMENTE - Si está en modo consulta y esperando pregunta ---
    if session.get("modo_consulta", False) and session.get("step") == "esperando_pregunta":
        logger.info(f"🔍 Buscando respuesta inteligente para: '{text}'")
        logger.info(f"📊 Estado de sesión: {session}")
        
        respuesta_faq = database.buscar_respuesta_inteligente(text)
        
        if respuesta_faq:
            logger.info(f"✅ FAQ ENCONTRADA - ID: {respuesta_faq['id']}")
            logger.info(f"📖 Pregunta BD: {respuesta_faq['pregunta']}")
            logger.info(f"📚 Categoría: {respuesta_faq['categoria']}")
            
            # ✅ GUARDAR LA CONSULTA EN LA BASE DE DATOS
            resultado_guardado = database.guardar_consulta_faq(
                numero=number,
                pregunta_usuario=text,
                fue_util=None  # Se actualizará después con el feedback
            )
            
            if resultado_guardado:
                logger.info(f"✅ Consulta guardada en BD - ID: {resultado_guardado['consulta_id']}")
                session['consulta_actual_id'] = resultado_guardado['consulta_id']
            else:
                logger.error("❌ Error al guardar consulta en BD")
            
            mensaje_respuesta = f"🤖 *Pregunta Frecuente* ({respuesta_faq['categoria']})\n\n"
            mensaje_respuesta += f"❓ *Pregunta:* {respuesta_faq['pregunta']}\n\n"
            mensaje_respuesta += f"💡 *Respuesta:* {respuesta_faq['respuesta']}\n\n"
            mensaje_respuesta += "¿Esta respuesta resolvió tu duda? Responde *si* o *no*"
            
            data = util.TextMessage(mensaje_respuesta, number)
            Whatsappservice.sendMessageWhatsapp(data)
            
            session['esperando_feedback_faq'] = True
            session['ultima_respuesta_faq'] = respuesta_faq['id']
            session['step'] = "esperando_feedback"
            return
        else:
            logger.warning(f"❌ NO SE ENCONTRÓ FAQ para: '{text}'")
            
            # ✅ GUARDAR LA CONSULTA AUNQUE NO HAYA RESPUESTA
            resultado_guardado = database.guardar_consulta_faq(
                numero=number,
                pregunta_usuario=text,
                fue_util=None
            )
            
            if resultado_guardado:
                logger.info(f"✅ Consulta (sin respuesta) guardada en BD - ID: {resultado_guardado['consulta_id']}")
            
            data = util.TextMessage(
                "🤔 No encontré una respuesta específica para tu pregunta en nuestra base de conocimientos.\n\n"
                "Puedes:\n"
                "• Intentar reformular tu pregunta\n"
                "• Crear un ticket para atención personalizada escribiendo *hola*\n"
                "• Contactar a soporte directo\n\n"
                "¿Qué te gustaría hacer?",
                number
            )
            Whatsappservice.sendMessageWhatsapp(data)
            session['step'] = "opciones_despues_sin_respuesta"
            return

    # --- Respuesta por defecto ---
    else:
        logger.warning(f"🤔 Mensaje no reconocido de {number}: {text}")
        data = util.TextMessage("🤔 No entendí tu mensaje. Escribe *hola* para ver las opciones disponibles.", number)
        Whatsappservice.sendMessageWhatsapp(data)
        return

def cleanup_old_sessions(hours_old=24):
    """Clean up sessions older than specified hours"""
    current_time = datetime.now()
    expired_numbers = []
    
    for number, session in user_sessions.items():
        if "created_at" in session:
            time_diff = current_time - session["created_at"]
            if time_diff.total_seconds() > hours_old * 3600:
                expired_numbers.append(number)
    
    for number in expired_numbers:
        del user_sessions[number]
    
    if expired_numbers:
        logger.info(f"🧹 Cleaned up {len(expired_numbers)} expired sessions")

@app.route('/status', methods=['GET'])
def status():
    """Endpoint para verificar el estado del sistema"""
    status_info = {
        "status": "online",
        "timestamp": datetime.now().isoformat(),
        "gpt5_mini_configured": ai_client is not None,
        "active_sessions": len(user_sessions),
        "version": "3.0.0-gpt5-mini"
    }
    return status_info

if __name__ == '__main__':
    logger.info("🚀 Iniciando servidor Flask con GPT-5 Mini...")
    logger.info("📞 Endpoints disponibles:")
    logger.info("   GET  /welcome - Página de bienvenida")
    logger.info("   GET  /whatsapp - Verificación webhook")
    logger.info("   POST /whatsapp - Recibir mensajes")
    logger.info("   GET  /test-ai - Probar GPT-5 Mini")
    logger.info("   GET  /status - Estado del sistema")
    
    logger.info("🔍 Diagnosticando estructura de BD...")
    database.diagnosticar_tablas()
    
    app.run(host='0.0.0.0', port=5000, debug=False)