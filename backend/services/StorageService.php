<?php

class StorageService
{
    public static function disk(): string
    {
        $config = self::config();
        return (string)($config['storage_disk'] ?? 'local');
    }

    public static function basePath(): string
    {
        $config = self::config();
        return rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/../uploads')), "\\/");
    }

    public static function resolveLocalPath(string $relativePath): string
    {
        $normalized = self::normalizeRelativePath($relativePath);
        return self::basePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }

    public static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path);
        $path = ltrim((string)$path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    public static function publicUrl(string $relativePath): ?string
    {
        $config = self::config();
        $baseUrl = trim((string)($config['storage_public_base_url'] ?? ''));
        if ($baseUrl === '') {
            return null;
        }
        return rtrim($baseUrl, '/') . '/' . self::normalizeRelativePath($relativePath);
    }

    public static function signedUrl(string $relativePath, int $ttlSeconds = 300): array
    {
        return [
            'disk' => self::disk(),
            'path' => self::normalizeRelativePath($relativePath),
            'url' => self::publicUrl($relativePath),
            'expires_at' => gmdate('c', time() + max(60, $ttlSeconds)),
            'signed' => false,
            'note' => self::disk() === 'local'
                ? 'Local storage uses authenticated backend download/stream endpoints.'
                : 'S3-compatible signed URL generation will be implemented when object storage is enabled.',
        ];
    }

    public static function capabilities(): array
    {
        return [
            'active_disk' => self::disk(),
            'local_storage_ready' => is_dir(self::basePath()) || @mkdir(self::basePath(), 0775, true),
            'object_storage_ready' => self::disk() !== 'local',
            'supports_signed_urls' => self::disk() !== 'local',
            'providers' => ['local', 's3', 'r2', 'b2', 'minio'],
        ];
    }

    private static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require __DIR__ . '/../config/app.php';
        }
        return $config;
    }
}
