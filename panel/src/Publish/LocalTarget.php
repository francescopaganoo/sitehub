<?php
declare(strict_types=1);

namespace Panel\Publish;

use Panel\Http\ApiError;

final class LocalTarget implements Target
{
    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public static function fromConfig(array $config): self
    {
        if (empty($config['path']) || !is_dir($config['path'])) {
            throw new ApiError(422, 'invalid_target', 'Cartella del sito inesistente: ' . ($config['path'] ?? ''));
        }
        return new self($config['path']);
    }

    public function read(string $path): ?string
    {
        $file = $this->file($path);
        return is_file($file) ? file_get_contents($file) : null;
    }

    public function list(string $dir): array
    {
        $out = [];
        foreach (glob($this->file($dir) . '/*') ?: [] as $f) {
            if (is_file($f)) {
                $out[] = ltrim($dir, '/') . '/' . basename($f);
            }
        }
        return $out;
    }

    public function commit(array $files, array $deletes, string $message): array
    {
        $written = 0;
        $deleted = 0;
        foreach ($files as $path => $content) {
            $file = $this->file($path);
            if (is_file($file) && file_get_contents($file) === $content) {
                continue;
            }
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, $content);
            $written++;
        }
        foreach ($deletes as $path) {
            $file = $this->file($path);
            if (is_file($file)) {
                unlink($file);
                $deleted++;
                // rimuove le cartelle rimaste vuote (es. public/vecchio-articolo/)
                for ($dir = dirname($file); $dir !== $this->root && is_dir($dir) && count(scandir($dir)) === 2; $dir = dirname($dir)) {
                    rmdir($dir);
                }
            }
        }
        $changed = $written + $deleted > 0;
        return ['revision' => $changed ? 'local-' . date('YmdHis') : null, 'written' => $written, 'deleted' => $deleted];
    }

    private function file(string $path): string
    {
        if (preg_match('~(^|/)\.\.(/|$)~', $path)) {
            throw new \InvalidArgumentException("Path non valido: $path");
        }
        return $this->root . '/' . ltrim($path, '/');
    }
}
