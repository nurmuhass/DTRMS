<?php
declare(strict_types=1);

namespace Dtrms\Core;

use Throwable;

final class View
{
    private static array $branding = [];

    public static function setBranding(array $branding): void
    {
        self::$branding = $branding;
    }

    public static function render(string $view, array $data): string
    {
        $directory = BASE_PATH . '/resources/views';
        $branding = self::$branding;
        ob_start();
        try {
            require $directory . '/layouts/main.php';
            return (string) ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }
}
