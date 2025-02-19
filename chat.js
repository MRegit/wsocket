let ws;
let username = '';
let wsRetryCount = 0;
const MAX_RETRY_ATTEMPTS = 3;
const AUTH_TOKEN = 'b8153f040e407fc7462a12e8e9e03fbd';
// Elementos del DOM
const loginModal = document.getElementById('loginModal');
const chatInterface = document.getElementById('chatInterface');
const usernameInput = document.getElementById('username');
const messageInput = document.getElementById('messageInput');
const messagesArea = document.getElementById('messagesArea');
const activeUsers = document.getElementById('activeUsers');
const joinButton = document.getElementById('joinButton');
const sendButton = document.getElementById('sendButton');

// Inicialización de eventos
document.addEventListener('DOMContentLoaded', initializeChat);

function initializeChat() {
    joinButton.addEventListener('click', joinChat);
    sendButton.addEventListener('click', sendMessage);
    messageInput.addEventListener('keypress', handleMessageInputKeypress);
    usernameInput.addEventListener('keypress', handleUsernameInputKeypress);
}

function joinChat() {
    username = usernameInput.value.trim();
    
    if (!username) {
        showError('Por favor ingresa un nombre');
        return;
    }

    initializeWebSocket();
}

function initializeWebSocket() {
    try {
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const wsUrl = `${protocol}//127.0.0.1:5000/chat`;
        
        ws = new WebSocket(wsUrl);
        
        ws.onopen = handleWebSocketOpen;
        ws.onmessage = handleWebSocketMessage;
        ws.onclose = handleWebSocketClose;
        ws.onerror = handleWebSocketError;
        
    } catch (error) {
        showError('Error al conectar con el servidor');
        console.error('WebSocket initialization error:', error);
    }
}

function handleWebSocketOpen() {
    wsRetryCount = 0;
    loginModal.classList.add('hidden');
    chatInterface.classList.remove('hidden');
    
    // Enviar mensaje de unión
    sendWebSocketMessage({
        type: 'join',
        username: username,
        token: AUTH_TOKEN,
        message: `${username} se ha unido al chat`
    });
}

function handleWebSocketMessage(event) {
    try {
        const data = JSON.parse(event.data);
        if (data.token && data.username && data.message) {
            displayMessage(data);
        }
    } catch (error) {
        console.error('Error processing message:', error);
    }
}

function handleWebSocketClose() {
    if (wsRetryCount < MAX_RETRY_ATTEMPTS) {
        wsRetryCount++;
        setTimeout(initializeWebSocket, 3000);
    } else {
        showError('Conexión perdida. Por favor, recarga la página.');
    }
}

function handleWebSocketError(error) {
    console.error('WebSocket error:', error);
    showError('Error en la conexión');
}

function displayMessage(data) {
    const messageDiv = document.createElement('div');
    const isOwnMessage = data.username === username;
    
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
    
    messagesArea.appendChild(messageDiv);
    scrollToBottom();
}

function sendMessage() {
    const message = messageInput.value.trim();
    
    if (!message) return;
    
    if (ws && ws.readyState === WebSocket.OPEN) {
        const messageData = {
            type: 'message',
            username: username,
            message: message,
            token: AUTH_TOKEN,
            timestamp: new Date().toISOString()
        };

        // Mostrar el mensaje propio inmediatamente
        displayMessage(messageData);
        
        // Enviar el mensaje al servidor
        sendWebSocketMessage(messageData);
        
        messageInput.value = '';
    } else {
        showError('Error de conexión. Intenta de nuevo.');
    }
}

function sendWebSocketMessage(message) {
    try {
        ws.send(JSON.stringify(message));
    } catch (error) {
        console.error('Error sending message:', error);
        showError('Error al enviar el mensaje');
    }
}

function handleMessageInputKeypress(e) {
    if (e.key === 'Enter') {
        sendMessage();
    }
}

function handleUsernameInputKeypress(e) {
    if (e.key === 'Enter') {
        joinChat();
    }
}

function scrollToBottom() {
    messagesArea.scrollTop = messagesArea.scrollHeight;
}

function showError(message) {
    alert(message);
}