import asyncio
import websockets
import logging
import json
import os
from datetime import datetime
from collections import defaultdict
from typing import Dict, Set, Optional
from dataclasses import dataclass
from pathlib import Path
from websockets.legacy.server import WebSocketServerProtocol
from dotenv import load_dotenv
from urllib.parse import urlparse

@dataclass
class Config:
    """Configuration class to store all server settings"""
    auth_token: str
    allowed_origins: Optional[list]
    max_connections_per_ip: Optional[int]
    host: str
    port: int
    log_file: str
    allowed_form_names: set

    @classmethod
    def load_from_env(cls) -> 'Config':
        """Load configuration from environment variables"""
        load_dotenv()

        # Procesar allowed_origins
        allowed_origins_str = os.getenv("WS_ALLOWED_ORIGINS", "")
        allowed_origins = [origin.strip() for origin in allowed_origins_str.split(",") if origin.strip()] if allowed_origins_str else None

        max_conn_str = os.getenv("WS_MAX_CONNECTIONS_PER_IP", "")
        max_connections = int(max_conn_str) if max_conn_str else None

        allowed_forms_str = os.getenv("WS_ALLOWED_FORMS", "chat_room,health_check")
        allowed_form_names = set(allowed_forms_str.split(","))

        auth_token = os.getenv("WS_AUTH_TOKEN")
        if auth_token is None:
            raise ValueError("WS_AUTH_TOKEN no está definido en el entorno.")

        return cls(
            auth_token=auth_token,
            allowed_origins=allowed_origins,
            max_connections_per_ip=max_connections,
            host=os.getenv("WS_HOST", "0.0.0.0"),
            port=int(os.getenv("WS_PORT", 5000)),
            log_file="websocket.log",
            allowed_form_names=allowed_form_names
        )


class ConnectionManager:
    """Manages WebSocket connections and their states"""
    def __init__(self):
        self.clients: Dict[str, Set[WebSocketServerProtocol]] = defaultdict(set)
        self.ip_connections: Dict[str, int] = defaultdict(int)
        self.form_connections: Dict[str, Set[WebSocketServerProtocol]] = defaultdict(set)

    def add_connection(self, ip: str, form_name: str, websocket: WebSocketServerProtocol) -> bool:
        """Add a new connection for an IP address and form"""
        self.ip_connections[ip] += 1
        self.form_connections[form_name].add(websocket)
        return True

    def remove_connection(self, ip: str, form_name: str, websocket: WebSocketServerProtocol):
        """Remove a connection for an IP address and form"""
        self.ip_connections[ip] = max(0, self.ip_connections[ip] - 1)
        if self.ip_connections[ip] == 0:
            del self.ip_connections[ip]
        
        if form_name in self.form_connections:
            self.form_connections[form_name].discard(websocket)
            if not self.form_connections[form_name]:
                del self.form_connections[form_name]

    def check_ip_limit(self, ip: str, max_connections: Optional[int]) -> bool:
        """Check if IP has reached connection limit"""
        if max_connections is None:
            return True
        return self.ip_connections[ip] < max_connections

    def get_form_connections_count(self, form_name: str) -> int:
        """Get number of connections in a specific form"""
        return len(self.form_connections.get(form_name, set()))


class WebSocketServer:
    """Main WebSocket server implementation"""
    def __init__(self, config: Config):
        self.config = config
        self.connection_manager = ConnectionManager()
        self.setup_logging()

    def setup_logging(self):
        """Configure logging with rotation"""
        log_dir = Path(__file__).parent / 'logs'   
        log_dir.mkdir(exist_ok=True)
        
        logging.basicConfig(
            level=logging.INFO,
            format='%(asctime)s [%(levelname)s] %(message)s',
            handlers=[
                logging.FileHandler(log_dir / self.config.log_file),
                logging.StreamHandler()
            ]
        )

    def validate_origin(self, origin: str) -> bool:
        """Validate if the origin is allowed"""
        if not self.config.allowed_origins:
            return True  # Si no hay orígenes definidos, permite todos
            
        try:
            parsed_origin = urlparse(origin)
            origin_domain = f"{parsed_origin.scheme}://{parsed_origin.netloc}"
            
            # Comprobar si el origen está en la lista de permitidos
            return origin_domain in self.config.allowed_origins
        except Exception as e:
            logging.error(f"Error validating origin {origin}: {e}")
            return False

    async def handle_health_check(self, websocket: WebSocketServerProtocol):
        """Handle health check requests"""
        response = {
            "status": "ok",
            "timestamp": datetime.now().isoformat(),
            "total_connections": sum(len(clients) for clients in self.connection_manager.form_connections.values()),
            "form_connections": {
                form: len(clients) 
                for form, clients in self.connection_manager.form_connections.items()
            }
        }
        await websocket.send(json.dumps(response))

    async def broadcast(self, form_name: str, message: str, sender: WebSocketServerProtocol):
        """Broadcast message to all clients in the same form"""
        if form_name not in self.connection_manager.form_connections:
            return

        disconnected = set()
        for client in self.connection_manager.form_connections[form_name]:
            if client != sender:
                try:
                    await client.send(message)
                except websockets.exceptions.ConnectionClosed:
                    disconnected.add(client)
                except Exception as e:
                    logging.error(f"Error broadcasting to client: {e}")
                    disconnected.add(client)

        # Clean up disconnected clients
        for client in disconnected:
            self.connection_manager.remove_connection(
                client.remote_address[0],
                form_name,
                client
            )

    async def handle_message(self, websocket: WebSocketServerProtocol, message: str, client_ip: str):
        """Process incoming messages"""
        try:
            data = json.loads(message)
            
            # Validación básica del mensaje
            if not all(key in data for key in ["token", "form_name"]):
                await websocket.send(json.dumps({"error": "Invalid message format"}))
                return False

            # Validación del token
            if data["token"] != self.config.auth_token:
                logging.warning(f"Invalid token from {client_ip}")
                await websocket.send(json.dumps({"error": "Invalid token"}))
                return False

            # Validación del form_name
            form_name = data["form_name"]
            if form_name not in self.config.allowed_form_names:
                await websocket.send(json.dumps({"error": "Invalid form_name"}))
                return False

            # Manejo de health check
            if form_name == "health_check":
                await self.handle_health_check(websocket)
                return True

            # Registro de la conexión al form si es nuevo
            self.connection_manager.add_connection(client_ip, form_name, websocket)
            
            # Broadcast del mensaje
            await self.broadcast(form_name, message, websocket)
            return True

        except json.JSONDecodeError:
            await websocket.send(json.dumps({"error": "Invalid JSON"}))
            return False
        except Exception as e:
            logging.error(f"Error handling message: {e}")
            return False

    async def handler(self, websocket: WebSocketServerProtocol):
        """Main WebSocket connection handler"""
        client_ip = websocket.remote_address[0]
        
        # Validación de origen
        if "origin" in websocket.request_headers:
            origin = websocket.request_headers["origin"]
            if not self.validate_origin(origin):
                logging.warning(f"Conexión rechazada desde origen no permitido: {origin}")
                await websocket.close(1008, "Origin not allowed")
                return
        elif self.config.allowed_origins:
            # Si se especificaron orígenes permitidos pero no se recibió origen, rechazar
            logging.warning(f"Conexión rechazada: no se especificó origen")
            await websocket.close(1008, "Origin required")
            return

        # Verificar límite de conexiones por IP
        if not self.connection_manager.check_ip_limit(client_ip, self.config.max_connections_per_ip):
            logging.warning(f"Too many connections from {client_ip}")
            await websocket.close(1008, "Too many connections")
            return

        current_form = None
        try:
            async for message in websocket:
                logging.info(f"Message received from {client_ip}")
                
                try:
                    data = json.loads(message)
                    current_form = data.get("form_name")
                except json.JSONDecodeError:
                    continue

                if not await self.handle_message(websocket, message, client_ip):
                    break

        except websockets.exceptions.ConnectionClosed:
            logging.info(f"Connection closed from {client_ip}")
        except Exception as e:
            logging.error(f"Connection error: {e}")
        finally:
            if current_form:
                self.connection_manager.remove_connection(client_ip, current_form, websocket)


async def main():
    """Main entry point"""
    config = Config.load_from_env()
    server = WebSocketServer(config)
    
    # Mostrar configuración de orígenes permitidos
    origins_msg = "Allowed origins: " + (
        ", ".join(config.allowed_origins) if config.allowed_origins 
        else "All origins allowed"
    )
    
    start_msg = f"WebSocket server running on {config.host}:{config.port}"
    print("\n" + "="*len(start_msg))
    print(start_msg)
    print(origins_msg)
    print("="*len(start_msg) + "\n")
    
    async with websockets.serve(
        server.handler,
        config.host,
        config.port,
        ping_interval=30,
        ping_timeout=10
    ):
        await asyncio.Future()

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\nServer stopped manually")