<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\App;
error_reporting(E_ALL);
ini_set('display_errors', 1);

function cargarEnv($ruta)
{
    if (!file_exists($ruta)) {
        throw new Exception("Archivo .env no encontrado");
    }

    $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        if (strpos($linea, '#') === 0) continue;
        list($clave, $valor) = explode('=', $linea, 2);
        $_ENV[trim($clave)] = trim($valor);
    }
}

// Cargar el archivo .env
cargarEnv(__DIR__ . '/../.env');
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
    private $logFile;

    public function __construct(string $logFile = __DIR__ . '/logs_ws/websocket.log')
    {
        $this->logFile = $logFile;

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

/**
 * Clase para el health check del WebSocket
 */
class HealthCheckHandler implements MessageComponentInterface
{
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->logger->info("Health Check - Conexión abierta desde: " . $conn->remoteAddress);
        try {
            $response = json_encode([
                'status' => 'healthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'WebSocket server is running'
            ]);
            $conn->send($response);
            $this->logger->info("Health Check - Respuesta enviada: " . $response);
        } catch (\Exception $e) {
            $this->logger->error("Health Check - Error al enviar respuesta: " . $e->getMessage());
        }
        $conn->close();
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->logger->error("Health Check - Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $conn->close();
    }

    public function onMessage(ConnectionInterface $from, $msg) {}
    public function onClose(ConnectionInterface $conn) {}
}

/**
 * Clase que maneja las conexiones WebSocket
 */
class WebSocketFormHandler implements MessageComponentInterface
{
    protected $clients;
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->clients = new \SplObjectStorage();
        $this->logger = $logger;
        $this->logger->info("WebSocketFormHandler construido");
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->logger->info("Nueva conexión abierta:");
        $this->logger->info("IP: " . $conn->remoteAddress);
        $this->logger->info("Headers: " . print_r($conn->httpRequest->getHeaders(), true));
        $this->logger->info("URI: " . $conn->httpRequest->getUri());
        
        $this->clients->attach($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->logger->info("Mensaje recibido de {$from->remoteAddress}: $msg");
        try {
            $data = json_decode($msg, true);
            $this->logger->info("Datos decodificados: " . print_r($data, true));
            
            if (empty($data['token'])) {
                $this->logger->warning("Token no proporcionado");
                return;
            }
            
            if ($data['token'] !== MD5($_ENV['WS_AUTH_TOKEN'])) {
                $this->logger->warning("Token inválido");
                return;
            }

            foreach ($this->clients as $client) {
                if ($from !== $client) {
                    $client->send($msg);
                    $this->logger->info("Mensaje reenviado a cliente");
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error procesando mensaje: " . $e->getMessage());
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->logger->info("Conexión cerrada desde: " . $conn->remoteAddress);
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->logger->error("Error en conexión {$conn->remoteAddress}: " . $e->getMessage());
        $this->logger->error("Stack trace: " . $e->getTraceAsString());
        $conn->close();
    }
}

// Inicializar logger
$logger = new FileLogger();

try {
    $host = isset($_ENV['WS_HOST']) ? $_ENV['WS_HOST'] : '0.0.0.0';
    $port = (int)(isset($_ENV['WS_PORT']) ? $_ENV['WS_PORT'] : 5000);

    $logger->info("Iniciando servidor WebSocket:");
    $logger->info("Host: $host");
    $logger->info("Port: $port");
    $logger->info("ENV vars: " . print_r($_ENV, true));

    $app = new App($host, $port, '0.0.0.0');
    
    // Añade una ruta de prueba simple
    $app->route('/test', new class($logger) implements MessageComponentInterface {
        protected $logger;
        
        public function __construct($logger) {
            $this->logger = $logger;
        }
        
        public function onOpen(ConnectionInterface $conn) {
            $this->logger->info("Test - Nueva conexión:");
            $this->logger->info("RemoteAddress: " . $conn->remoteAddress);
            $this->logger->info("ResourceId: " . $conn->resourceId);
            $conn->send("Test connection successful");
        }
        
        public function onMessage(ConnectionInterface $from, $msg) {
            $this->logger->info("Test - Mensaje recibido: $msg");
        }
        
        public function onClose(ConnectionInterface $conn) {
            $this->logger->info("Test - Conexión cerrada");
        }
        
        public function onError(ConnectionInterface $conn, \Exception $e) {
            $this->logger->error("Test - Error: " . $e->getMessage());
        }
    }, ['*']);
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
        $app->route($route, new WebSocketFormHandler($logger), ['*']);
    }

    $logger->info("Servidor WebSocket iniciado correctamente");
    $app->run();
} catch (\Exception $e) {
    $logger->error("Error crítico: " . $e->getMessage());
    $logger->error("Stack trace: " . $e->getTraceAsString());
    throw $e;
}