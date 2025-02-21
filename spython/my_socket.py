# servidor.py
import asyncio
import websockets
import logging
import json
from datetime import datetime

# Configurar logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    filename='websocket_py.log'
)

# Almacenar conexiones activas
CLIENTS = set()

async def handle_connection(websocket, path):
    try:
        # Registrar nueva conexión
        client_info = f"{websocket.remote_address[0]}:{websocket.remote_address[1]}"
        logging.info(f"Nueva conexión desde {client_info}")
        
        # Añadir cliente al set de conexiones
        CLIENTS.add(websocket)
        
        # Enviar mensaje de bienvenida
        await websocket.send(json.dumps({
            "type": "welcome",
            "message": "Conectado al servidor WebSocket",
            "timestamp": datetime.now().isoformat()
        }))

        # Mantener conexión y escuchar mensajes
        async for message in websocket:
            try:
                # Registrar mensaje recibido
                logging.info(f"Mensaje recibido de {client_info}: {message}")
                
                # Enviar eco del mensaje
                response = {
                    "type": "echo",
                    "original_message": message,
                    "timestamp": datetime.now().isoformat()
                }
                await websocket.send(json.dumps(response))
                
            except Exception as e:
                logging.error(f"Error procesando mensaje: {str(e)}")
                
    except websockets.exceptions.ConnectionClosed:
        logging.info(f"Cliente desconectado normalmente: {client_info}")
    except Exception as e:
        logging.error(f"Error en la conexión: {str(e)}")
    finally:
        # Remover cliente del set de conexiones
        CLIENTS.remove(websocket)
        logging.info(f"Conexión cerrada con {client_info}")

async def main():
    # Iniciar servidor
    host = "0.0.0.0"  # Escuchar en todas las interfaces
    port = 5000
    
    logging.info(f"Iniciando servidor WebSocket en {host}:{port}")
    
    async with websockets.serve(handle_connection, host, port):
        logging.info("Servidor WebSocket iniciado correctamente")
        await asyncio.Future()  # Ejecutar indefinidamente

if __name__ == "__main__":
    asyncio.run(main())