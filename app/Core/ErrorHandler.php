<?php
declare(strict_types=1);

namespace Dtrms\Core;

use ErrorException;
use PDOException;
use Throwable;

final class ErrorHandler
{
    public static function register(string $logFile): void
    {
        ini_set('display_errors', '0');
        ini_set('zend.exception_ignore_args', '1');
        ini_set('log_errors', '1');
        ini_set('error_log', $logFile);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $error): void {
            $reference = bin2hex(random_bytes(8));
            $driverCode = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
            $schema = $error instanceof SchemaUnavailable || in_array($driverCode, [1054, 1142, 1146, 1356, 1449], true);
            $unavailable = $schema || in_array($driverCode, [1044, 1045, 1049, 2002, 2003, 2006, 2013], true);
            $details = [];
            for ($item = $error; $item !== null; $item = $item->getPrevious()) {
                $entry = ['exception' => get_class($item), 'file' => $item->getFile(), 'line' => $item->getLine(),
                    'trace' => $item->getTraceAsString()];
                if ($item instanceof PDOException) {
                    $entry['sqlstate'] = $item->errorInfo[0] ?? $item->getCode();
                    $entry['mysql_error'] = $item->errorInfo[1] ?? null;
                    // Only known identifier diagnostics can be logged verbatim. Other driver text can contain data.
                    if (preg_match("/\\A(?:Table|Unknown column|View) '[A-Za-z0-9_.]+' (?:doesn't exist|in '[A-Za-z_ ]+'|references invalid table)/",
                        $item->errorInfo[2] ?? '', $match)) {
                        $entry['diagnostic'] = $match[0];
                    }
                } elseif ($item instanceof SchemaUnavailable) {
                    $entry['diagnostic'] = $item->getMessage();
                }
                $details[] = $entry;
            }
            error_log(json_encode(['reference' => $reference, 'exceptions' => $details], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Operation failed. See storage/logs/application.log (reference $reference).\n");
                exit(1);
            }
            if (!headers_sent() && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
                try {
                    echo HttpError::response($unavailable ? 503 : 500, $schema ? 'schema_unavailable' : ($unavailable ? 'database_unavailable' : 'server_error'),
                        $unavailable ? 'This service is temporarily unavailable. Contact your DTRMS administrator.' : 'An unexpected error occurred. Please try again.', $reference);
                } catch (Throwable) {
                    header('Content-Type: text/plain; charset=UTF-8');
                    echo "DTRMS could not complete this request. Reference: $reference.";
                }
            } elseif (!headers_sent()) {
                http_response_code($unavailable ? 503 : 500);
            }
        });
    }
}
