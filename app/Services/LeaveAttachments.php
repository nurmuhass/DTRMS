<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DomainException;

final class LeaveAttachments
{
    private const MAX_SIZE = 5 * 1024 * 1024;
    private const TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];

    public function __construct(private string $directory)
    {
    }

    public function store(?array $upload): ?string
    {
        if ($upload === null || ($upload['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $tmp = $upload['tmp_name'] ?? null;
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)
            || ($size = @filesize($tmp)) === false || $size < 1 || $size > self::MAX_SIZE) {
            throw new DomainException('Upload one PDF, PNG, or JPEG file up to 5 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new DomainException('Upload a valid PDF, PNG, or JPEG file.');
        }
        $key = bin2hex(random_bytes(32)) . '.' . $extension;
        $target = $this->root() . DIRECTORY_SEPARATOR . $key;
        if (!@move_uploaded_file($tmp, $target)) {
            throw new DomainException('The document could not be stored. Contact the administrator.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && !@chmod($target, 0600)) {
            @unlink($target);
            throw new DomainException('The document could not be stored securely.');
        }
        return $key;
    }

    public function download(string $key): ?array
    {
        try {
            $file = $this->path($key);
        } catch (DomainException) {
            return null;
        }
        if ($file === null) {
            return null;
        }
        $bytes = @file_get_contents($file, length: self::MAX_SIZE + 1);
        if ($bytes === false || strlen($bytes) > self::MAX_SIZE) {
            return null;
        }
        $ext = pathinfo($key, PATHINFO_EXTENSION);
        return ['bytes' => $bytes, 'name' => 'leave-document.' . $ext, 'mime' => array_search($ext, self::TYPES, true)];
    }

    public function discard(?string $key): void
    {
        if ($key === null) {
            return;
        }
        try {
            $file = $this->path($key);
            if ($file !== null && !@unlink($file)) {
                error_log('Leave document cleanup failed.');
            }
        } catch (DomainException) {
            error_log('Leave document storage unavailable during cleanup.');
        }
    }

    private function path(string $key): ?string
    {
        if (!preg_match('/\A[0-9a-f]{64}\.(?:pdf|png|jpg)\z/', $key)) {
            return null;
        }
        $file = $this->root() . DIRECTORY_SEPARATOR . $key;
        return !is_link($file) && is_file($file) ? $file : null;
    }

    private function root(): string
    {
        $root = !is_link($this->directory) && preg_match('~^(?:/|[a-z]:[/\\\\])~i', $this->directory)
            ? realpath($this->directory) : false;
        if ($root === false || !is_dir($root) || !is_writable($root)
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 0077) !== 0)) {
            throw new DomainException('Private leave document storage is unavailable. Contact the administrator.');
        }
        $normalize = static fn (string $path): string => rtrim(str_replace(chr(92), '/', $path), '/');
        $target = $normalize($root);
        if (PHP_OS_FAMILY === 'Windows') {
            $target = strtolower($target);
        }
        $blocked = [BASE_PATH];
        for ($ancestor = dirname(BASE_PATH); $ancestor !== dirname($ancestor); $ancestor = dirname($ancestor)) {
            if (in_array(strtolower(basename($ancestor)), ['htdocs', 'wwwroot', 'public_html'], true)) {
                $blocked[] = $ancestor;
            }
        }
        foreach ($blocked as $path) {
            $path = $normalize(realpath($path) ?: $path);
            $path = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
            if ($target === $path || str_starts_with($target, $path . '/')) {
                throw new DomainException('Private leave document storage is unavailable. Contact the administrator.');
            }
        }
        return $root;
    }
}
