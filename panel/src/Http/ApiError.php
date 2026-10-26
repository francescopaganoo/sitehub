<?php
declare(strict_types=1);

namespace Panel\Http;

/**
 * Errore restituito al client come:
 *   {"error": {"code": "validation_failed", "message": "...", "fields": {"title": "..."}}}
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what): self
    {
        return new self(404, 'not_found', "$what non trovato");
    }

    public static function validation(array $fields): self
    {
        return new self(422, 'validation_failed', 'Dati non validi', $fields);
    }
}
