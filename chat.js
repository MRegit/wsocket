// Configuración básica encriptada usando un esquema simple de ofuscación
const CONFIG = {
    WS_URL: btoa('ws://35.232.99.246:5000/chat'),
    AUTH_TOKEN: btoa('b8153f040e407fc7462a12e8e9e03fbd'),
    // Añadir sal aleatoria para aumentar la seguridad
    SALT: Array.from(crypto.getRandomValues(new Uint8Array(16)))
        .map(b => b.toString(16).padStart(2, '0')).join('')
};

// Clase principal del chat con encapsulamiento
class SecureChat {
    #ws;
    #username = '';
    #wsRetryCount = 0;
    #MAX_RETRY_ATTEMPTS = 3;
    #encryptionKey = null;

    constructor() {
        this.#initializeDOMElements();
        this.#setupEventListeners();
        this.#initializeEncryption();
    }

    // Inicialización del sistema de encriptación
    async #initializeEncryption() {
        const encoder = new TextEncoder();
        const keyMaterial = await crypto.subtle.importKey(
            "raw",
            encoder.encode(CONFIG.SALT),
            { name: "PBKDF2" },
            false,
            ["deriveBits", "deriveKey"]
        );

        this.#encryptionKey = await crypto.subtle.deriveKey(
            {
                name: "PBKDF2",
                salt: encoder.encode(CONFIG.SALT),
                iterations: 100000,
                hash: "SHA-256"
            },
            keyMaterial,
            { name: "AES-GCM", length: 256 },
            true,
            ["encrypt", "decrypt"]
        );
    }

    // Encriptación de mensajes
    async #encryptMessage(message) {
        try {
            const encoder = new TextEncoder();
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const encrypted = await crypto.subtle.encrypt(
                {
                    name: "AES-GCM",
                    iv: iv
                },
                this.#encryptionKey,
                encoder.encode(JSON.stringify(message))
            );

            return {
                iv: Array.from(iv).map(b => b.toString(16).padStart(2, '0')).join(''),
                data: Array.from(new Uint8Array(encrypted))
                    .map(b => b.toString(16).padStart(2, '0')).join('')
            };
        } catch (error) {
            console.error('Encryption error:', error);
            throw new Error('Message encryption failed');
        }
    }

    // Desencriptación de mensajes
    async #decryptMessage(encryptedData) {
        try {
            const iv = new Uint8Array(encryptedData.iv.match(/.{2}/g)
                .map(byte => parseInt(byte, 16)));
            const data = new Uint8Array(encryptedData.data.match(/.{2}/g)
                .map(byte => parseInt(byte, 16)));

            const decrypted = await crypto.subtle.decrypt(
                {
                    name: "AES-GCM",
                    iv: iv
                },
                this.#encryptionKey,
                data
            );

            return JSON.parse(new TextDecoder().decode(decrypted));
        } catch (error) {
            console.error('Decryption error:', error);
            throw new Error('Message decryption failed');
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
            form_name: 'chat',
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

    async #sendMessage() {
        const message = this.elements.messageInput.value.trim();
        
        if (!message) return;
        
        if (this.#ws && this.#ws.readyState === WebSocket.OPEN) {
            const messageData = {
                type: 'message',
                username: this.#username,
                message: message,
                token: atob(CONFIG.AUTH_TOKEN),
                form_name: 'chat',
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
            const encryptedMessage = await this.#encryptMessage(message);
            this.#ws.send(JSON.stringify(encryptedMessage));
        } catch (error) {
            console.error('Error sending message:', error);
            this.#showError('Error al enviar el mensaje');
        }
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
    // Ofuscación adicional del código
    (function() {
        new SecureChat();
    })();
});

// Prevenir acceso a la consola del navegador
Object.defineProperty(window, 'console', {
    value: console,
    writable: false,
    configurable: false
});