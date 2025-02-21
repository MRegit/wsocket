import asyncio
import websockets
import logging
import json
import os
from datetime import datetime

# Configuración de logging
LOG_FILE = "logs_ws/websocket.log"
os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s', handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler()])

# Cargar variables de entorno desde .env
def cargar_env(ruta):
    if not os.path.exists(ruta):
        raise FileNotFoundError("Archivo .env no encontrado")
    with open(ruta) as f:
        for linea in f:
            if linea.strip() and not linea.startswith('#'):
                clave, valor = linea.strip().split('=', 1)
                os.environ[clave] = valor

cargar_env("../.env")

# Clase para manejar conexiones WebSocket
class WebSocketFormHandler:
    def __init__(self):
        self.clients = set()
        logging.info("WebSocketFormHandler inicializado")

    async def handler(self, websocket, path):
        self.clients.add(websocket)
        logging.info(f"Nueva conexión desde {websocket.remote_address}")
        try:
            async for message in websocket:
                logging.info(f"Mensaje recibido: {message}")
                data = json.loads(message)
                
                if 'token' not in data or data['token'] != os.environ.get('WS_AUTH_TOKEN'):
                    logging.warning("Token inválido")
                    continue
                
                for client in self.clients:
                    if client != websocket:
                        await client.send(message)
                        logging.info("Mensaje reenviado a otro cliente")
        except Exception as e:
            logging.error(f"Error en conexión: {e}")
        finally:
            self.clients.remove(websocket)
            logging.info(f"Conexión cerrada desde {websocket.remote_address}")

# Health Check Handler
async def health_check(websocket, path):
    logging.info(f"Health Check - Conexión desde {websocket.remote_address}")
    response = json.dumps({
        'status': 'healthy',
        'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'message': 'WebSocket server is running'
    })
    await websocket.send(response)
    logging.info(f"Health Check - Respuesta enviada: {response}")
    await websocket.close()

# Configurar servidor WebSocket
async def main():
    host = os.environ.get('WS_HOST', '0.0.0.0')
    port = int(os.environ.get('WS_PORT', 5000))
    
    handler = WebSocketFormHandler()
    
    start_server = websockets.serve(handler.handler, host, port)
    start_health = websockets.serve(health_check, host, port + 1)
    
    logging.info(f"Servidor WebSocket iniciado en {host}:{port}")
    await asyncio.gather(start_server, start_health)

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logging.info("Servidor WebSocket detenido manualmente")
