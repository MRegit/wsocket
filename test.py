import asyncio
import websockets
import json

async def test_health_check():
    uri = "ws://localhost:5000"
    async with websockets.connect(uri) as websocket:
        health_message = {
            "token": "token_secreto",
            "form_name": "health_check"
        }
        
        await websocket.send(json.dumps(health_message))
        response = await websocket.recv()
        print("Health Check Response:", json.loads(response))

if __name__ == "__main__":
    asyncio.run(test_health_check())