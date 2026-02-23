import requests
import os
import logging

logger = logging.getLogger(__name__)

# Consider using environment variables for security
WHATSAPP_TOKEN = os.getenv("WHATSAPP_TOKEN", "EAAWoIV7RvhMBPhcZA8MBTSGUszaeOQEnmk6kWPpMKRG4KhlQxifMPInBG1mseMZBD9q913hgGqwy1UcLf6n9F9ZBuzvtLI7mhwl4SZBo2kfUszRf3czCWvoN2HgVf2lpcP6EqaDynZAYgVq1yQYaBC2aMZCLzFu8vevJvtNHl6lf73yDaIyHt5YyqeCfH958lhM3uooHjWGYvKeaDO6AqmifnaZCsdU4RBaFdAvCs8dhhAZD")
API_URL = "https://graph.facebook.com/v22.0/120166967852779/messages"

def sendMessageWhatsapp(data):
    try:
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {WHATSAPP_TOKEN}"
        }

        response = requests.post(API_URL, json=data, headers=headers, timeout=30)

        if response.status_code == 200:
            logger.info("✅ Mensaje enviado correctamente")
            return True
        else:
            logger.error(f"❌ Error en WhatsApp API: {response.status_code} - {response.text}")
            return False

    except requests.exceptions.Timeout:
        logger.error("❌ Timeout al enviar mensaje a WhatsApp API")
        return False
    except requests.exceptions.ConnectionError:
        logger.error("❌ Error de conexión con WhatsApp API")
        return False
    except Exception as exception:
        logger.error(f"❌ Error inesperado al enviar mensaje: {exception}")
        return False