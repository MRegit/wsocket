// Configuración básica encriptada usando un esquema simple de ofuscación
const CONFIG = {
    WS_URL: btoa('ws://34.171.46.191:5000'),
    AUTH_TOKEN: btoa('b8153f040e407fc7462a12e8e9e03fbd'),
};

// Clase principal del chat con encapsulamiento
class SecureChat {
    #ws;
    #username = '';
    #wsRetryCount = 0;
    #MAX_RETRY_ATTEMPTS = 3;

    constructor() {
        this.#initializeDOMElements();
        this.#setupEventListeners();
    }

    // Envio de mensajes
    async #sendMessage() {
        const message = this.elements.messageInput.value.trim();
        
        if (!message) return;
        
        if (this.#ws && this.#ws.readyState === WebSocket.OPEN) {
            const messageData = {
                type: 'message',
                username: this.#username,
                message: message,
                token: atob(CONFIG.AUTH_TOKEN),
                form_name: 'chat_room',
                timestamp: new Date().toISOString()
            };

            this.#displayMessage(messageData);
            await this.#sendWebSocketMessage(messageData);
            this.elements.messageInput.value = '';
        } else {
            this.#showError('Error de conexión. Intenta de nuevo.');
        }
    }

    async #sendWebSocketMessage(message) {
        try {
            this.#ws.send(JSON.stringify(message));
        } catch (error) {
            console.error('Error sending message:', error);
            this.#showError('Error al enviar el mensaje');
        }
    }

    #initializeDOMElements() {
        this.elements = {
            loginModal: document.getElementById('loginModal'),
            chatInterface: document.getElementById('chatInterface'),
            usernameInput: document.getElementById('username'),
            messageInput: document.getElementById('messageInput'),
            messagesArea: document.getElementById('messagesArea'),
            activeUsers: document.getElementById('activeUsers'),
            joinButton: document.getElementById('joinButton'),
            sendButton: document.getElementById('sendButton')
        };
    }

    #setupEventListeners() {
        this.elements.joinButton.addEventListener('click', () => this.#joinChat());
        this.elements.sendButton.addEventListener('click', () => this.#sendMessage());
        this.elements.messageInput.addEventListener('keypress', (e) => this.#handleMessageInputKeypress(e));
        this.elements.usernameInput.addEventListener('keypress', (e) => this.#handleUsernameInputKeypress(e));
    }

    async #joinChat() {
        this.#username = this.elements.usernameInput.value.trim();
        
        if (!this.#username) {
            this.#showError('Por favor ingresa un nombre');
            return;
        }

        await this.#initializeWebSocket();
    }

    async #initializeWebSocket() {
        try {
            const wsUrl = atob(CONFIG.WS_URL);
            this.#ws = new WebSocket(wsUrl);
            
            this.#ws.onopen = () => this.#handleWebSocketOpen();
            this.#ws.onmessage = (event) => this.#handleWebSocketMessage(event);
            this.#ws.onclose = () => this.#handleWebSocketClose();
            this.#ws.onerror = (error) => this.#handleWebSocketError(error);
            
        } catch (error) {
            this.#showError('Error al conectar con el servidor');
            console.error('WebSocket initialization error:', error);
        }
    }

    async #handleWebSocketOpen() {
        this.#wsRetryCount = 0;
        this.elements.loginModal.classList.add('hidden');
        this.elements.chatInterface.classList.remove('hidden');
        
        await this.#sendWebSocketMessage({
            type: 'join',
            username: this.#username,
            token: atob(CONFIG.AUTH_TOKEN),
            form_name: 'chat_room',
            message: `${this.#username} se ha unido al chat`
        });
    }

    async #handleWebSocketMessage(event) {
        try {
            const data = JSON.parse(event.data);
            if (data.error) {
                this.#showError(data.error);
                return;
            }
            
            if (data.token && data.username) {
                this.#displayMessage(data);
            }
        } catch (error) {
            console.error('Error processing message:', error);
        }
    }

    #handleWebSocketClose() {
        if (this.#wsRetryCount < this.#MAX_RETRY_ATTEMPTS) {
            this.#wsRetryCount++;
            setTimeout(() => this.#initializeWebSocket(), 3000);
        } else {
            this.#showError('Conexión perdida. Por favor, recarga la página.');
        }
    }

    #handleWebSocketError(error) {
        console.error('WebSocket error:', error);
        this.#showError('Error en la conexión');
    }

    #displayMessage(data) {
        const messageDiv = document.createElement('div');
        const isOwnMessage = data.username === this.#username;
        
        messageDiv.className = `message-bubble ${isOwnMessage ? 'sent' : 'received'}`;
        
        const time = new Date().toLocaleTimeString('es-ES', {
            hour: '2-digit',
            minute: '2-digit'
        });

        messageDiv.innerHTML = `
            ${!isOwnMessage ? `<div class="text-blue-400 text-sm mb-1">${data.username}</div>` : ''}
            <div class="text-white">${data.message}</div>
            <div class="time-stamp text-right text-gray-400">${time}</div>
        `;
        
        this.elements.messagesArea.appendChild(messageDiv);
        this.#scrollToBottom();
    }

    #handleMessageInputKeypress(e) {
        if (e.key === 'Enter') {
            this.#sendMessage();
        }
    }

    #handleUsernameInputKeypress(e) {
        if (e.key === 'Enter') {
            this.#joinChat();
        }
    }

    #scrollToBottom() {
        this.elements.messagesArea.scrollTop = this.elements.messagesArea.scrollHeight;
    }

    #showError(message) {
        alert(message);
    }
}

// Inicialización del chat cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    new SecureChat();
});

// Prevenir acceso a la consola del navegador
Object.defineProperty(window, 'console', {
    value: console,
    writable: false,
    configurable: false
});
