<?php

declare(strict_types=1);

namespace SaudiZATCA\Services;

use SaudiZATCA\Exceptions\ZatcaException;

/**
 * Storage Service
 *
 * Handles file operations for certificates and keys.
 */
class StorageService
{
    public function __construct(
        private readonly string $basePath
    ) {
        $this->ensureDirectoryExists($this->basePath);
    }

    /**
     * Store content to file
     */
    public function put(string $path, string $content): void
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        $this->ensureDirectoryExists($dir);

        if (file_put_contents($fullPath, $content) === false) {
            throw new ZatcaException("Failed to write file: {$path}");
        }
    }

    /**
     * Get content from file
     */
    public function get(string $path): ?string
    {
        $fullPath = $this->fullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    /**
     * Check if file exists
     */
    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    /**
     * Delete file
     */
    public function delete(string $path): bool
    {
        $fullPath = $this->fullPath($path);

        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }

        return false;
    }

    /**
     * Get full path
     */
    public function fullPath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Check if path is absolute
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') ||
               str_starts_with($path, '\\') ||
               (strlen($path) > 2 && $path[1] === ':' && $path[2] === '\\');
    }
}
