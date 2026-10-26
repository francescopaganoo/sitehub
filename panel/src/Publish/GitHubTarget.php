<?php
declare(strict_types=1);

namespace Panel\Publish;

use Http;
use Panel\Http\ApiError;

/**
 * Pubblica su un repo GitHub con la Git Data API: un solo commit per pubblicazione,
 * senza git installato sul server. Coolify vede il push e fa il deploy.
 */
final class GitHubTarget implements Target
{
    private const API = 'https://api.github.com';

    public function __construct(private string $repo, private string $branch, private string $token)
    {
    }

    public static function fromConfig(array $config): self
    {
        $token = getenv('GITHUB_TOKEN') ?: '';
        if ($token === '') {
            throw new ApiError(500, 'github_not_configured', 'Variabile GITHUB_TOKEN non impostata sul pannello');
        }
        if (empty($config['repo']) || !preg_match('~^[\w.-]+/[\w.-]+$~', $config['repo'])) {
            throw new ApiError(422, 'invalid_target', 'Repo GitHub non valido (formato: proprietario/nome)');
        }
        return new self($config['repo'], $config['branch'] ?? 'main', $token);
    }

    public function read(string $path): ?string
    {
        $res = $this->call('GET', "/repos/{$this->repo}/contents/" . $this->encodePath($path) . '?ref=' . rawurlencode($this->branch), null, true);
        if ($res === null || ($res['type'] ?? '') !== 'file') {
            return null;
        }
        if (($res['content'] ?? '') === '' && ($res['size'] ?? 0) > 0) {
            // oltre 1 MB la Contents API non include il contenuto
            $res = $this->call('GET', "/repos/{$this->repo}/git/blobs/{$res['sha']}");
        }
        return base64_decode($res['content']);
    }

    public function list(string $dir): array
    {
        $res = $this->call('GET', "/repos/{$this->repo}/contents/" . $this->encodePath($dir) . '?ref=' . rawurlencode($this->branch), null, true);
        return array_values(array_map(fn($e) => $e['path'], array_filter($res ?? [], fn($e) => ($e['type'] ?? '') === 'file')));
    }

    public function commit(array $files, array $deletes, string $message): array
    {
        $head = $this->call('GET', "/repos/{$this->repo}/git/ref/heads/" . rawurlencode($this->branch))['object']['sha'];
        $baseTree = $this->call('GET', "/repos/{$this->repo}/git/commits/$head")['tree']['sha'];

        // sha dei file attuali: si calcola lo sha git in locale e si inviano solo i file cambiati
        $existing = [];
        $tree = $this->call('GET', "/repos/{$this->repo}/git/trees/$baseTree?recursive=1");
        foreach ($tree['tree'] as $e) {
            if ($e['type'] === 'blob') {
                $existing[$e['path']] = $e['sha'];
            }
        }
        if (!empty($tree['truncated'])) {
            $existing = []; // repo enorme: si riscrive tutto, la correttezza non cambia
        }

        $entries = [];
        foreach ($files as $path => $content) {
            $path = ltrim($path, '/');
            if (($existing[$path] ?? null) === sha1('blob ' . strlen($content) . "\0" . $content)) {
                continue;
            }
            $entry = ['path' => $path, 'mode' => '100644', 'type' => 'blob'];
            if (mb_check_encoding($content, 'UTF-8') && !str_contains($content, "\0")) {
                $entry['content'] = $content;
            } else {
                $entry['sha'] = $this->call('POST', "/repos/{$this->repo}/git/blobs",
                    ['content' => base64_encode($content), 'encoding' => 'base64'])['sha'];
            }
            $entries[] = $entry;
        }
        $written = count($entries);
        foreach ($deletes as $path) {
            $path = ltrim($path, '/');
            if (isset($existing[$path]) && !isset($files[$path])) {
                $entries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
            }
        }
        if (!$entries) {
            return ['revision' => null, 'written' => 0, 'deleted' => 0];
        }

        $newTree = $this->call('POST', "/repos/{$this->repo}/git/trees", ['base_tree' => $baseTree, 'tree' => $entries])['sha'];
        $commit = $this->call('POST', "/repos/{$this->repo}/git/commits",
            ['message' => $message, 'tree' => $newTree, 'parents' => [$head]])['sha'];
        $this->call('PATCH', "/repos/{$this->repo}/git/refs/heads/" . rawurlencode($this->branch), ['sha' => $commit]);

        return ['revision' => $commit, 'written' => $written, 'deleted' => count($entries) - $written];
    }

    private function call(string $method, string $path, ?array $body = null, bool $nullOn404 = false): ?array
    {
        $res = Http::request($method, self::API . $path, [
            'Authorization'        => "Bearer {$this->token}",
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ], $body);
        if ($res['status'] === 404 && $nullOn404) {
            return null;
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $detail = $res['json']['message'] ?? $res['error'] ?: 'errore sconosciuto';
            throw new ApiError(502, 'github_error', "GitHub $method $path: HTTP {$res['status']} – $detail");
        }
        return $res['json'] ?? [];
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }
}
