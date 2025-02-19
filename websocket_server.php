<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\App;
function cargarEnv($ruta)
{
    if (!file_exists($ruta)) {
        throw new Exception("Archivo .env no encontrado");
    }

    $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        if (strpos($linea, '#') === 0) continue; // Ignorar comentarios
        list($clave, $valor) = explode('=', $linea, 2);
        $_ENV[trim($clave)] = trim($valor);
    }
}

// Cargar el archivo .env
cargarEnv(__DIR__ . '/.env');

// Lista de IPs permitidas (se puede definir en .env)
$allowedIps = !empty($_ENV['ALLOWED_IPS']) ? explode(',', $_ENV['ALLOWED_IPS']) : [];
/**
 * Clase para el health check del WebSocket
 */
class HealthCheckHandler implements MessageComponentInterface
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $response = json_encode([
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'WebSocket server is running'
        ]);
        $conn->send($response);
        $conn->close();

        $this->logger->info("Health check realizado desde {$conn->remoteAddress}");
    }

    public function onMessage(ConnectionInterface $from, $msg) {}
    public function onClose(ConnectionInterface $conn) {}
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->logger->error("Error en health check: {$e->getMessage()}");
        $conn->close();
    }
}

/**
 * Clase que maneja las conexiones WebSocket
 */
class WebSocketFormHandler implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;
    protected LoggerInterface $logger;
    protected array $allowedIps;

    public function __construct(LoggerInterface $logger, array $allowedIps)
    {
        $this->clients = new \SplObjectStorage();
        $this->logger = $logger;
        $this->allowedIps = $allowedIps;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $ip = $conn->remoteAddress ?? '';

        // Solo validar IP si hay IPs permitidas configuradas
        if (!empty($this->allowedIps) && !in_array($ip, $this->allowedIps, true)) {
            $this->logger->warning("Conexión bloqueada desde IP no permitida: $ip");
            $conn->close();
            return;
        }

        $this->clients->attach($conn);
        $this->logger->info("Nueva conexión desde $ip - ID: {$conn->resourceId}");
    }
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        // Validar token de autenticación
        if (empty($data['token']) || $data['token'] !== MD5($_ENV['WS_AUTH_TOKEN'])) {
            $this->logger->warning("Token inválido recibido de ID: {$from->resourceId} - IP: {$from->remoteAddress} - Token: {$data['token']}");
            return;
        }

        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send($msg);
            }
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        $this->logger->info("Conexión cerrada - ID: {$conn->resourceId}");
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->logger->error("Error en ID {$conn->resourceId}: {$e->getMessage()}");
        $conn->close();
    }
}

/**
 * Interfaz de Logger
 */
interface LoggerInterface
{
    public function info(string $message);
    public function warning(string $message);
    public function error(string $message);
}

/**
 * Clase de Logger para entornos de producción
 */
class FileLogger implements LoggerInterface
{
    private string $logFile;

    public function __construct(string $logFile = __DIR__ . '/logs_ws/websocket.log')
    {
        $this->logFile = $logFile;

        // Crear directorio si no existe
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }
    }

    private function log(string $level, string $message)
    {
        file_put_contents($this->logFile, "[" . date("Y-m-d H:i:s") . "] [$level] $message\n", FILE_APPEND);
    }

    public function info(string $message)
    {
        $this->log("INFO", $message);
    }

    public function warning(string $message)
    {
        $this->log("WARNING", $message);
    }

    public function error(string $message)
    {
        $this->log("ERROR", $message);
    }
}

// Inicializar logger
$logger = new FileLogger();

try {
    $host = $_ENV['WS_HOST'] ?? '127.0.0.1';
    $port = (int)($_ENV['WS_PORT'] ?? 8080);

    $app = new App($host, $port);
    // Agregar ruta de health check
    $app->route('/health', new HealthCheckHandler($logger), ['*']);
    $routes = [
        '/chat',
        '/cirugia',
        '/admision',
        '/enfermeria',
        '/preanestesico',
        '/postanestesico',
        '/transanestesico',
        '/cirugia_unificado'
    ];

    foreach ($routes as $route) {
        $app->route($route, new WebSocketFormHandler($logger, $allowedIps), ['*']);
    }

    $logger->info("Servidor WebSocket iniciado en wss://$host:$port");
    $app->run();
} catch (Exception $e) {
    $logger->error("Error crítico: " . $e->getMessage());
}
