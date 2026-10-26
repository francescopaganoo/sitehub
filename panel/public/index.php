<?php
declare(strict_types=1);

/*
 * Front controller del pannello.
 *   /api/v1/…  API REST JSON (pannello web, gestionale, integrazioni) – token Bearer
 *   /e/v1/…    endpoint pubblici per i siti (script embed, invio form) – chiave pk_…
 *
 * Sviluppo: php -S 0.0.0.0:8080 panel/public/index.php
 */

require __DIR__ . '/../bootstrap.php';

use Panel\Api;
use Panel\Auth;
use Panel\Embed;
use Panel\Forms\FormService;
use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;
use Panel\Http\Router;
use Panel\Publish\Publisher;
use Panel\Store;

$req = Request::fromGlobals();

try {
    $db = panel_db();
    $store = new Store($db);
    $forms = new FormService($db);

    if (preg_match('~^/e/' . Embed::VERSION . '(/.*)$~', $req->path, $m)) {
        // endpoint pubblici usati dai siti: niente token, li identifica la chiave pk_…
        $response = (new Embed($store, $forms))->handle($req, $m[1]);
    } elseif (preg_match('~^/api/v1(/.*)?$~', $req->path, $m)) {
        $principal = (new Auth($db))->authenticate($req);
        $publisher = new Publisher($store, $db);

        $router = new Router();
        foreach ([Api\SitesApi::class, Api\PostsApi::class, Api\CategoriesApi::class] as $api) {
            (new $api($store, $publisher, $principal))->routes($router);
        }
        (new Api\FormsApi($store, $publisher, $principal, $forms))->routes($router);

        $response = $router->dispatch($req->withPath($m[1] ?? '/'));
    } else {
        throw new ApiError(404, 'not_found', 'Endpoint non trovato');
    }
} catch (ApiError $e) {
    $response = Response::error($e);
} catch (Throwable $e) {
    error_log((string) $e);
    $response = Response::error(new ApiError(500, 'internal_error', 'Errore interno del pannello'));
}

$response->send();
