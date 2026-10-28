<?php
declare(strict_types=1);

namespace Panel\Api;

use Panel\Http\Router;
use Panel\Publish\Publisher;
use Panel\Store;

/** Base delle risorse API: dipendenze comuni e pubblicazione automatica dopo le modifiche. */
abstract class Api
{
    public function __construct(
        protected Store $store,
        protected Publisher $publisher,
        protected array $principal,
    ) {
    }

    abstract public function routes(Router $r): void;

    /**
     * Se il sito ha auto_publish (default sì) pubblica subito.
     * Un errore di pubblicazione non annulla il salvataggio: viene riportato nella risposta.
     *
     * @return ?array{revision:?string, written:int, deleted:int}|array{error:string}
     */
    protected function autoPublish(array $site, string $message, bool $force = false): ?array
    {
        // blog disattivato: gli articoli si salvano, ma sul sito non va niente finché non si riattiva
        if (empty($site['modules']['blog']) || (!$force && !($site['config']['auto_publish'] ?? true))) {
            return null;
        }
        try {
            return $this->publisher->publish($site, $this->principal['name'], $message);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function site(string $slug): array
    {
        $site = $this->store->site($slug, $this->principal);
        // le date degli articoli sono in ora locale del sito
        if (!empty($site['config']['timezone'])) {
            date_default_timezone_set($site['config']['timezone']);
        }
        return $site;
    }
}
