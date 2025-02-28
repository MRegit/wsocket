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

@dataclass
class Config:
    """Configuration class to store all server settings"""
    auth_token: str
    allowed_origins: Optional[list]
    max_connections_per_ip: Optional[int]
    host: str
    port: int
    log_file: str

    @classmethod
    def load_from_env(cls) -> 'Config':
        """Load configuration from environment variables"""
        load_dotenv()  # Carga las variables de entorno desde el archivo .env

        # Parse allowed origins only if provided
        allowed_origins_str = os.getenv("WS_ALLOWED_ORIGINS", "")
        allowed_origins = allowed_origins_str.split(",") if allowed_origins_str else None

        # Parse max connections only if provided
        max_conn_str = os.getenv("WS_MAX_CONNECTIONS_PER_IP", "")
        max_connections = int(max_conn_str) if max_conn_str else None

        auth_token = os.getenv("WS_AUTH_TOKEN")
        if auth_token is None:
            raise ValueError("WS_AUTH_TOKEN no está definido en el entorno.")

        return cls(
            auth_token=auth_token,
            allowed_origins=allowed_origins,
            max_connections_per_ip=max_connections,
            host=os.getenv("WS_HOST", "0.0.0.0"),
            port=int(os.getenv("WS_PORT", 5000)),
            log_file="websocket.log"
        )


class ConnectionManager:
    """Manages WebSocket connections and their states"""
    def __init__(self):
        self.clients: Dict[str, Set[WebSocketServerProtocol]] = defaultdict(set)
        self.ip_connections: Dict[str, int] = defaultdict(int)

    def add_connection(self, ip: str) -> bool:
        """Add a new connection for an IP address"""
        self.ip_connections[ip] += 1
        return True

    def remove_connection(self, ip: str):
        """Remove a connection for an IP address"""
        self.ip_connections[ip] = max(0, self.ip_connections[ip] - 1)
        if self.ip_connections[ip] == 0:
            del self.ip_connections[ip]

    def check_ip_limit(self, ip: str, max_connections: Optional[int]) -> bool:
        """Check if IP has reached connection limit"""
        if max_connections is None:
            return True
        return self.ip_connections[ip] < max_connections

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

    async def handle_health_check(self, websocket: WebSocketServerProtocol):
        """Handle health check requests"""
        response = json.dumps({
            "status": "ok",
            "timestamp": datetime.now().isoformat(),
            "connections": len(self.connection_manager.clients)
        })
        await websocket.send(response)

    async def broadcast(self, form_name: str, message: str, sender: WebSocketServerProtocol):
        """Broadcast message to all clients in the same form"""
        if form_name not in self.connection_manager.clients:
            return

        disconnected = set()
        for client in self.connection_manager.clients[form_name]:
            if client != sender:
                try:
                    await client.send(message)
                except websockets.exceptions.ConnectionClosed:
                    disconnected.add(client)
                except Exception as e:
                    logging.error(f"Error broadcasting to client: {e}")
                    disconnected.add(client)

        # Clean up disconnected clients
        self.connection_manager.clients[form_name] -= disconnected

    async def handle_message(self, websocket: WebSocketServerProtocol, message: str):
        """Process incoming messages"""
        try:
            data = json.loads(message)
            
            if "token" not in data or data["token"] != self.config.auth_token:
                logging.warning(f"Token recibido: {data.get('token')}, Token esperado: {self.config.auth_token}")
                await websocket.send(json.dumps({"error": "Invalid token"}))
                return False

            if "form_name" not in data:
                await websocket.send(json.dumps({"error": "form_name required"}))
                return False

            form_name = data["form_name"]
            
            if form_name == "health_check":
                await self.handle_health_check(websocket)
                return True  # Changed to True to keep connection alive

            self.connection_manager.clients[form_name].add(websocket)
            await self.broadcast(form_name, message, websocket)
            return True

        except json.JSONDecodeError:
            await websocket.send(json.dumps({"error": "Invalid JSON"}))
            return False
        except Exception as e:
            logging.error(f"Error handling message: {e}")
            return False

    async def handler(self, websocket: WebSocketServerProtocol):  # Removed path parameter
        """Main WebSocket connection handler"""
        client_ip = websocket.remote_address[0]

        # Check IP limits if configured
        if not self.connection_manager.check_ip_limit(client_ip, self.config.max_connections_per_ip):
            logging.warning(f"Too many connections from {client_ip}")
            await websocket.close()
            return

        self.connection_manager.add_connection(client_ip)
        
        try:
            async for message in websocket:
                logging.info(f"Message received from {client_ip}")
                if not await self.handle_message(websocket, message):
                    break

        except websockets.exceptions.ConnectionClosed:
            logging.info(f"Connection closed from {client_ip}")
        except Exception as e:
            logging.error(f"Connection error: {e}")
        finally:
            self.connection_manager.remove_connection(client_ip)
            # Remove from all forms
            for clients in self.connection_manager.clients.values():
                clients.discard(websocket)

async def main():
    """Main entry point"""
    config = Config.load_from_env()
    server = WebSocketServer(config)
    
    start_msg = f"WebSocket server running on {config.host}:{config.port}"
    print("\n" + "="*len(start_msg))
    print(start_msg)
    print("="*len(start_msg) + "\n")
    
    async with websockets.serve(
        server.handler,
        config.host,
        config.port,
        ping_interval=30,  # Keep connections alive
        ping_timeout=10
    ):
        await asyncio.Future()  # Keep server running

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\nServer stopped manually")