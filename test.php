<!DOCTYPE html>
<html>
<head>
    <title>WebSocket Test</title>
</head>
<body>
    <h2>WebSocket Test</h2>
    <div id="status"></div>
    <div id="messages"></div>

    <script>
        const status = document.getElementById('status');
        const messages = document.getElementById('messages');

        function addMessage(msg) {
            const div = document.createElement('div');
            div.textContent = msg;
            messages.appendChild(div);
        }

        try {
            status.textContent = 'Conectando...';
            // Prueba primero con ws:// y si no funciona, cambia a wss://
            const ws = new WebSocket(`ws://${window.location.hostname}:5000/test`);

            ws.onopen = () => {
                status.textContent = 'Conectado';
                addMessage('Conexión establecida');
            };

            ws.onmessage = (event) => {
                addMessage(`Mensaje recibido: ${event.data}`);
            };

            ws.onerror = (error) => {
                status.textContent = 'Error';
                addMessage(`Error: ${error.message || 'Unknown error'}`);
                console.error('WebSocket error:', error);
            };

            ws.onclose = () => {
                status.textContent = 'Desconectado';
                addMessage('Conexión cerrada');
            };
        } catch (error) {
            status.textContent = 'Error';
            addMessage(`Error al crear WebSocket: ${error.message}`);
            console.error('Error al crear WebSocket:', error);
        }
    </script>
</body>
</html>