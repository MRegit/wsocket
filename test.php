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
        
        function addMessage(msg, isError = false) {
            const div = document.createElement('div');
            div.textContent = `${new Date().toISOString()}: ${msg}`;
            if (isError) div.style.color = 'red';
            messages.appendChild(div);
        }

        try {
            const ip = window.location.hostname;
            const url = `ws://${ip}:5000/test`;
            addMessage(`Intentando conectar a: ${url}`);
            status.textContent = 'Conectando...';
            
            const ws = new WebSocket(url);

            ws.onopen = () => {
                status.textContent = 'Conectado';
                addMessage('Conexión establecida');
                // Enviar un mensaje de prueba
                ws.send('ping');
            };

            ws.onmessage = (event) => {
                addMessage(`Mensaje recibido: ${event.data}`);
            };

            ws.onerror = (error) => {
                status.textContent = 'Error';
                addMessage(`Error de WebSocket: ${error.message || 'Error desconocido'}`, true);
                console.error('WebSocket error:', error);
            };

            ws.onclose = (event) => {
                status.textContent = 'Desconectado';
                addMessage(`Conexión cerrada. Código: ${event.code}, Razón: ${event.reason || 'No especificada'}`, true);
            };
        } catch (error) {
            status.textContent = 'Error';
            addMessage(`Error al crear WebSocket: ${error.message}`, true);
            console.error('Error al crear WebSocket:', error);
        }
    </script>
</body>
</html>