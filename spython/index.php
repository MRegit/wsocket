<!DOCTYPE html>
<html>
<head>
    <title>WebSocket Test Client</title>
    <style>
        .message { margin: 5px 0; padding: 5px; }
        .error { color: red; }
        .success { color: green; }
        .info { color: blue; }
    </style>
</head>
<body>
    <h2>WebSocket Test Client</h2>
    <div id="status"></div>
    <hr>
    <input type="text" id="messageInput" placeholder="Escribe un mensaje">
    <button onclick="sendMessage()">Enviar</button>
    <hr>
    <div id="messages"></div>

    <script>
        const status = document.getElementById('status');
        const messages = document.getElementById('messages');
        const messageInput = document.getElementById('messageInput');
        let ws = null;

        function addMessage(msg, type = 'info') {
            const div = document.createElement('div');
            div.className = `message ${type}`;
            div.textContent = `${new Date().toLocaleTimeString()}: ${msg}`;
            messages.insertBefore(div, messages.firstChild);
        }

        function connect() {
            try {
                const ip = window.location.hostname;
                const url = `ws://${ip}:5000`;
                addMessage(`Intentando conectar a: ${url}`);
                status.textContent = 'Conectando...';
                
                ws = new WebSocket(url);

                ws.onopen = () => {
                    status.textContent = 'Conectado';
                    addMessage('Conexión establecida', 'success');
                };

                ws.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        addMessage(`Servidor: ${JSON.stringify(data)}`, 'info');
                    } catch {
                        addMessage(`Servidor: ${event.data}`, 'info');
                    }
                };

                ws.onerror = (error) => {
                    status.textContent = 'Error';
                    addMessage(`Error: ${error.message || 'Error desconocido'}`, 'error');
                };

                ws.onclose = (event) => {
                    status.textContent = 'Desconectado';
                    ws = null;
                    addMessage(`Conexión cerrada. Código: ${event.code}`, 'error');
                    
                    // Reconectar después de 5 segundos
                    setTimeout(connect, 5000);
                };
            } catch (error) {
                status.textContent = 'Error';
                addMessage(`Error de conexión: ${error.message}`, 'error');
                setTimeout(connect, 5000);
            }
        }

        function sendMessage() {
            if (!ws || ws.readyState !== WebSocket.OPEN) {
                addMessage('No hay conexión con el servidor', 'error');
                return;
            }

            const message = messageInput.value.trim();
            if (message) {
                ws.send(message);
                addMessage(`Tú: ${message}`, 'success');
                messageInput.value = '';
            }
        }

        messageInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') sendMessage();
        });

        // Iniciar conexión
        connect();
    </script>
</body>
</html>