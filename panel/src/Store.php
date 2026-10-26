<?php
declare(strict_types=1);

namespace Panel;

use Panel\Http\ApiError;

/**
 * Accesso ai dati di siti, articoli e categorie.
 * Le colonne JSON vengono decodificate qui: fuori da questa classe si lavora solo con array.
 */
final class Store
{
    private const SITE_JSON = ['target', 'config', 'templates', 'manifest', 'modules', 'origins'];
    private const POST_JSON = ['categories', 'tags', 'image', 'seo'];

    public function __construct(private Db $db)
    {
    }

    // ---------------------------------------------------------------- siti

    /** @param ?int $onlyId limita a un sito (token di sito) */
    public function sites(?int $onlyId = null): array
    {
        $rows = $onlyId === null
            ? $this->db->all('SELECT * FROM sites ORDER BY name')
            : $this->db->all('SELECT * FROM sites WHERE id = ?', [$onlyId]);
        return array_map(fn($r) => $this->decode($r, self::SITE_JSON), $rows);
    }

    /** Sito per slug, verificando che il token possa accederci. */
    public function site(string $slug, array $principal): array
    {
        $row = $this->db->one('SELECT * FROM sites WHERE slug = ?', [$slug]);
        if (!$row || ($principal['site_id'] !== null && $principal['site_id'] !== (int) $row['id'])) {
            throw ApiError::notFound('Sito');
        }
        return $this->decode($row, self::SITE_JSON);
    }

    /** Sito dalla chiave pubblica inserita nell'HTML (endpoint pubblici, senza token). */
    public function siteByKey(string $publicKey): ?array
    {
        $row = $this->db->one('SELECT * FROM sites WHERE public_key = ?', [$publicKey]);
        return $row ? $this->decode($row, self::SITE_JSON) : null;
    }

    public function createSite(array $site): array
    {
        $now = date('c');
        $id = $this->db->insert('sites', $this->encode($site, self::SITE_JSON) + ['created_at' => $now, 'updated_at' => $now]);
        return $this->decode($this->db->one('SELECT * FROM sites WHERE id = ?', [$id]), self::SITE_JSON);
    }

    public function updateSite(int $id, array $set): void
    {
        $this->db->update('sites', $this->encode($set, self::SITE_JSON) + ['updated_at' => date('c')], 'id = :id', ['id' => $id]);
    }

    /** Elimina il sito: articoli, categorie, moduli, messaggi e token del sito vanno via con lui (ON DELETE CASCADE). */
    public function deleteSite(int $id): void
    {
        $this->db->run('DELETE FROM sites WHERE id = ?', [$id]);
    }

    /** Riepilogo dei messaggi dei form (spam escluso), per gli elenchi. */
    public function submissionStats(int $siteId): array
    {
        $row = $this->db->one("SELECT COUNT(*) total, SUM(status IN ('failed', 'pending')) unsent, MAX(created_at) last_at
            FROM submissions WHERE site_id = ? AND status != 'spam'", [$siteId]);
        return ['total' => (int) $row['total'], 'unsent' => (int) $row['unsent'], 'last_at' => $row['last_at']];
    }

    public function publications(int $siteId, int $limit): array
    {
        return $this->db->all(sprintf('SELECT at, actor, message, revision, written, deleted, error FROM publish_log
            WHERE site_id = ? ORDER BY id DESC LIMIT %d', $limit), [$siteId]);
    }

    // ---------------------------------------------------------------- articoli

    /**
     * @param array{status?:string, category?:string, q?:string} $filters
     * @return array{0: array[], 1: int} articoli della pagina, totale
     */
    public function posts(int $siteId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['site_id = :site'];
        $params = ['site' => $siteId];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'EXISTS (SELECT 1 FROM json_each(posts.categories) WHERE value = :cat)';
            $params['cat'] = $filters['category'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(title LIKE :q OR content LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->one("SELECT COUNT(*) n FROM posts WHERE $sqlWhere", $params)['n'];
        $rows = $this->db->all(sprintf('SELECT * FROM posts WHERE %s ORDER BY date DESC LIMIT %d OFFSET %d',
            $sqlWhere, $perPage, ($page - 1) * $perPage), $params);
        return [array_map(fn($r) => $this->decode($r, self::POST_JSON), $rows), $total];
    }

    /** Tutti gli articoli del sito (per la pubblicazione). */
    public function allPosts(int $siteId): array
    {
        return array_map(fn($r) => $this->decode($r, self::POST_JSON),
            $this->db->all('SELECT * FROM posts WHERE site_id = ? ORDER BY date DESC', [$siteId]));
    }

    public function post(int $siteId, string $id): array
    {
        $row = $this->db->one('SELECT * FROM posts WHERE site_id = ? AND id = ?', [$siteId, $id]);
        if (!$row) {
            throw ApiError::notFound('Articolo');
        }
        return $this->decode($row, self::POST_JSON);
    }

    public function slugTaken(int $siteId, string $slug, ?string $exceptId = null): bool
    {
        return (bool) $this->db->one('SELECT 1 FROM posts WHERE site_id = ? AND slug = ? AND id != ?', [$siteId, $slug, $exceptId ?? '']);
    }

    public function savePost(int $siteId, array $post): void
    {
        $row = $this->encode($post, self::POST_JSON);
        $exists = $this->db->one('SELECT 1 FROM posts WHERE site_id = ? AND id = ?', [$siteId, $post['id']]);
        if ($exists) {
            unset($row['id'], $row['created_at']);
            $this->db->update('posts', $row, 'site_id = :site AND id = :id', ['site' => $siteId, 'id' => $post['id']]);
        } else {
            $this->db->insert('posts', $row + ['site_id' => $siteId]);
        }
    }

    public function deletePost(int $siteId, string $id): void
    {
        $this->db->run('DELETE FROM posts WHERE site_id = ? AND id = ?', [$siteId, $id]);
    }

    // ---------------------------------------------------------------- categorie

    public function categories(int $siteId): array
    {
        return array_map(fn($r) => $this->decodeCategory($r),
            $this->db->all('SELECT slug, name, description, seo FROM categories WHERE site_id = ? ORDER BY name', [$siteId]));
    }

    public function category(int $siteId, string $slug): array
    {
        $row = $this->db->one('SELECT slug, name, description, seo FROM categories WHERE site_id = ? AND slug = ?', [$siteId, $slug]);
        if (!$row) {
            throw ApiError::notFound('Categoria');
        }
        return $this->decodeCategory($row);
    }

    public function saveCategory(int $siteId, array $cat, ?string $oldSlug = null): void
    {
        $row = $this->encode($cat, ['seo']);
        if ($oldSlug !== null) {
            $this->db->update('categories', $row, 'site_id = :site AND slug = :old', ['site' => $siteId, 'old' => $oldSlug]);
            if ($oldSlug !== $cat['slug']) {
                $this->renameCategoryInPosts($siteId, $oldSlug, $cat['slug']);
            }
        } else {
            $this->db->insert('categories', $row + ['site_id' => $siteId]);
        }
    }

    public function deleteCategory(int $siteId, string $slug): void
    {
        $this->db->run('DELETE FROM categories WHERE site_id = ? AND slug = ?', [$siteId, $slug]);
        $this->renameCategoryInPosts($siteId, $slug, null);
    }

    private function renameCategoryInPosts(int $siteId, string $from, ?string $to): void
    {
        foreach ($this->allPosts($siteId) as $post) {
            if (in_array($from, $post['categories'], true)) {
                $cats = array_values(array_unique(array_filter(array_map(fn($c) => $c === $from ? $to : $c, $post['categories']))));
                $this->db->update('posts', ['categories' => json_encode($cats)], 'site_id = :site AND id = :id',
                    ['site' => $siteId, 'id' => $post['id']]);
            }
        }
    }

    /** seo sempre come oggetto con entrambe le chiavi: i client si aspettano una forma stabile. */
    private function decodeCategory(array $row): array
    {
        $row = $this->decode($row, ['seo']);
        $row['seo'] = array_intersect_key(($row['seo'] ?: []) + ['title' => '', 'description' => ''], ['title' => 1, 'description' => 1]);
        return $row;
    }

    // ---------------------------------------------------------------- JSON

    private function decode(array $row, array $jsonCols): array
    {
        foreach ($jsonCols as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = $row[$c] === null ? null : json_decode($row[$c], true);
            }
        }
        if (isset($row['id']) && is_numeric($row['id']) && in_array('target', $jsonCols, true)) {
            $row['id'] = (int) $row['id'];
        }
        return $row;
    }

    private function encode(array $row, array $jsonCols): array
    {
        foreach ($jsonCols as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = $row[$c] === null ? null : json_encode($row[$c], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        return $row;
    }
}
