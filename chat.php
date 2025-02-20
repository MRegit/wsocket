<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Futurista</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="chat-container">
    <!-- Login Modal -->
    <div id="loginModal" class="fixed inset-0 flex items-center justify-center z-50 bg-black bg-opacity-50">
        <div class="glass-effect p-8 rounded-2xl w-96">
            <h2 class="text-2xl text-white mb-6 font-bold text-center">Bienvenido al Chat</h2>
            <div class="space-y-4">
                <input type="text" id="username" placeholder="Ingresa tu nombre" 
                       class="w-full p-3 rounded-lg bg-white bg-opacity-10 text-white placeholder-gray-400 outline-none focus:ring-2 focus:ring-blue-500">
                <button id="joinButton"
                        class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition duration-300">
                    Unirse al Chat
                </button>
            </div>
        </div>
    </div>

    <!-- Chat Interface -->
    <div id="chatInterface" class="hidden h-screen flex flex-col">
        <!-- Header -->
        <div class="glass-effect p-4">
            <h1 class="text-xl text-white font-bold">Chat Grupal</h1>
            <p id="activeUsers" class="text-gray-400 text-sm"></p>
        </div>

        <!-- Messages Area -->
        <div id="messagesArea" class="flex-1 overflow-y-auto p-4 space-y-4">
            <!-- Messages will be inserted here -->
        </div>

        <!-- Input Area -->
        <div class="glass-effect p-4">
            <div class="flex space-x-4">
                <input type="text" id="messageInput" 
                       class="flex-1 p-3 rounded-lg bg-white bg-opacity-10 text-white placeholder-gray-400 outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Escribe tu mensaje...">
                <button id="sendButton"
                        class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition duration-300">
                    Enviar
                </button>
            </div>
        </div>
    </div>

    <script src="chat.js"></script>
</body>
</html>