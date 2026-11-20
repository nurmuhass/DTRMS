<?php
declare(strict_types=1);

namespace Dtrms\Core;

final class HttpError
{
    public static function wantsJson(): bool
    {
        return str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    public static function response(int $status, string $code, string $message, ?string $reference = null): string
    {
        http_response_code($status);
        if (self::wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            $error = ['code' => $code, 'message' => $message];
            if ($reference !== null) {
                $error['reference'] = $reference;
            }
            return json_encode(['error' => $error], JSON_THROW_ON_ERROR);
        }

        return View::render('error', ['title' => match ($status) {
            403 => 'Access denied', 404 => 'Page not found', 405 => 'Method not allowed',
            422 => 'Check your request', 503 => 'Service unavailable', default => 'Request unavailable',
        }, 'message' => $reference === null ? $message : "$message Reference: $reference."]);
    }
}
