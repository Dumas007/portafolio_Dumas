def MenuMessage(number):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "interactive",
        "interactive": {
            "type": "list",
            "body": {"text": "Seleccione una opción:"},
            "footer": {"text": "Estamos para ayudarte"},
            "action": {
                "button": "Ver opciones",
                "sections": [
                    {
                        "title": "Opciones de soporte",
                        "rows": [
                            {"id": "main-ticket", "title": "🎫 Ticket/IT"},
                            {"id": "main-consulta", "title": "❓ Consulta general"}
                        ]
                    }
                ]
            }
        }
    }

def PreguntaNecesidad(number):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "text",
        "text": {"body": "✏️ Por favor, describe tu necesidad:"}
    }

def PreguntaUrgencia(number):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "interactive",
        "interactive": {
            "type": "button",
            "body": {"text": "⚡ Selecciona el nivel de urgencia:"},
            "action": {
                "buttons": [
                    {"type": "reply", "reply": {"id": "urg_baja", "title": "🟢 Baja"}},
                    {"type": "reply", "reply": {"id": "urg_media", "title": "🟡 Media"}},
                    {"type": "reply", "reply": {"id": "urg_alta", "title": "🔴 Alta"}}
                ]
            }
        }
    }

def PreguntaDepartamento(number):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "interactive",
        "interactive": {
            "type": "list",
            "header": {"type": "text", "text": "📂 Selecciona un departamento"},
            "body": {"text": "Elige el área que atenderá tu ticket:"},
            "footer": {"text": "Soporte disponible 24/7"},
            "action": {
                "button": "Elegir departamento",
                "sections": [
                    {
                        "title": "Departamentos",
                        "rows": [
                            {"id": "dep_it", "title": "💻 IT / Sistemas"},
                            {"id": "dep_rrhh", "title": "👥 Recursos Humanos"},
                            {"id": "dep_finanzas", "title": "💵 Finanzas"},
                            {"id": "dep_backoffice", "title": "📑 BackOffice"},
                            {"id": "dep_marketing", "title": "📱 Marketing"},
                            {"id": "dep_ventas", "title": "🔥 Ventas"},
                            {"id": "dep_otros", "title": "🔧 Otros"}
                        ]
                    }
                ]
            }
        }
    }

def TextMessage(body, number):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "text",
        "text": {"body": body}
    }

def PreguntaEvaluacion(number):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "interactive",
        "interactive": {
            "type": "button",
            "body": {"text": "🤔 ¿Te fue útil la información proporcionada por nuestro técnico virtual?"},
            "footer": {"text": "Tu feedback nos ayuda a mejorar el servicio"},
            "action": {
                "buttons": [
                    {"type": "reply", "reply": {"id": "eval_si", "title": "✅ Sí, fue útil"}},
                    {"type": "reply", "reply": {"id": "eval_no", "title": "❌ No, no fue útil"}}
                ]
            }
        }
    }

def MensajeGracias(number):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "text",
        "text": {"body": "🙏 ¡Gracias por tu feedback! Tu opinión es muy valiosa para mejorar nuestro servicio."}
    }

def MensajeSinPermiso(number):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "text",
        "text": {
            "body": "⛔ *ACCESO DENEGADO*\n\nNo tienes permisos para usar este servicio. \n\nPor favor, contacta al administrador para solicitar acceso."
        }
    }

def MensajeTecnicoAsignado(number, tecnico):
    return {
        "messaging_product": "whatsapp",
        "to": number,
        "type": "text",
        "text": {
            "body": f"👨‍💼 *Técnico Asignado*\n\n"
                   f"• 🧑‍💻 *Nombre:* {tecnico['nombre']}\n"
                   f"• 📧 *Email:* {tecnico['email']}\n"
                   f"• 📞 *Teléfono:* {tecnico['telefono']}\n"
                   f"El técnico se contactará contigo pronto."
        }
    }