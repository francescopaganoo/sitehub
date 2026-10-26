<?php
declare(strict_types=1);

namespace Panel\Publish;

/** Dove vive il repo di un sito: GitHub (produzione, deploy Coolify) o una cartella locale (sviluppo). */
interface Target
{
    /** Contenuto di un file del repo, null se non esiste. */
    public function read(string $path): ?string;

    /** File (path completi) dentro una cartella del repo. */
    public function list(string $dir): array;

    /**
     * Scrive e cancella file in un'unica revisione. Solo i file realmente cambiati vengono scritti.
     *
     * @param array<string,string> $files   path → contenuto
     * @param string[]             $deletes path da rimuovere (quelli inesistenti vengono ignorati)
     * @return array{revision:?string, written:int, deleted:int} revision null = nulla da pubblicare
     */
    public function commit(array $files, array $deletes, string $message): array;

    public static function fromConfig(array $config): self;
}
