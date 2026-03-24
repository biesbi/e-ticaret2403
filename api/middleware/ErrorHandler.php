<?php
// ═══════════════════════════════════════════════
//  ErrorHandler
//  Production'da detay gizle, her zaman logla
// ═══════════════════════════════════════════════

class ErrorHandler
{
    public static function register(): void
    {
        // PHP hata gösterimini kapat
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');

        $logDir  = rtrim(env('LOG_DIR', __DIR__ . '/../logs'), '/');
        $logFile = $logDir . '/errors.log';

        // PHP hatalarını dosyaya yaz
        ini_set('log_errors', '1');
        ini_set('error_log', $logFile);
        error_reporting(E_ALL);

        // Yakalanmamış exception handler
        set_exception_handler(function (Throwable $e) use ($logFile) {
            self::logError($e, $logFile);
            self::respond($e);
        });

        // Fatal error handler
        register_shutdown_function(function () use ($logFile) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $msg = "[FATAL] {$err['message']} in {$err['file']}:{$err['line']}";
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " $msg\n", FILE_APPEND | LOCK_EX);
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
                }
            }
        });
    }

    private static function logError(Throwable $e, string $logFile): void
    {
        if (env('LOG_ENABLED', 'true') !== 'true') return;

        $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $uri     = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $method  = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
        $line    = sprintf(
            "%s [ERROR] %s: %s in %s:%d | IP=%s METHOD=%s URI=%s\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $ip,
            $method,
            $uri
        );

        $logDir = dirname($logFile);
        if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private static function respond(Throwable $e): never
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        // Production'da detay gösterme
        if (env('APP_ENV') === 'production') {
            echo json_encode([
                'success' => false,
                'message' => 'Sunucu hatası oluştu. Lütfen daha sonra tekrar deneyin.',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // Development'ta detay göster
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => explode("\n", $e->getTraceAsString()),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        exit;
    }
}
