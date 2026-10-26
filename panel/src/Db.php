<?php
declare(strict_types=1);

namespace Panel;

use PDO;

final class Db
{
    public readonly PDO $pdo;

    /** Ogni voce è una migrazione: si aggiungono in coda, non si modificano. */
    private const MIGRATIONS = [
        <<<SQL
        CREATE TABLE sites (
            id            INTEGER PRIMARY KEY,
            slug          TEXT NOT NULL UNIQUE,
            name          TEXT NOT NULL,
            domain        TEXT NOT NULL,
            target        TEXT NOT NULL,           -- JSON {type: github|local, ...}
            config        TEXT NOT NULL DEFAULT '{}', -- JSON: blog, site_name, auto_publish…
            templates     TEXT NOT NULL DEFAULT '{}', -- JSON {single, archive}
            manifest      TEXT NOT NULL DEFAULT '[]', -- file generati all'ultima pubblicazione
            published_at  TEXT,
            created_at    TEXT NOT NULL,
            updated_at    TEXT NOT NULL
        );
        CREATE TABLE posts (
            id          TEXT NOT NULL,
            site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            slug        TEXT NOT NULL,
            title       TEXT NOT NULL,
            excerpt     TEXT NOT NULL DEFAULT '',
            content     TEXT NOT NULL DEFAULT '',
            status      TEXT NOT NULL DEFAULT 'draft',
            date        TEXT NOT NULL,
            modified    TEXT NOT NULL,
            categories  TEXT NOT NULL DEFAULT '[]',
            tags        TEXT NOT NULL DEFAULT '[]',
            image       TEXT,
            author      TEXT NOT NULL DEFAULT '',
            seo         TEXT NOT NULL DEFAULT '{}',
            head_extra  TEXT NOT NULL DEFAULT '',
            wp_id       INTEGER,
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL,
            PRIMARY KEY (site_id, id),
            UNIQUE (site_id, slug)
        );
        CREATE INDEX posts_site_date ON posts(site_id, date DESC);
        CREATE TABLE categories (
            site_id      INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            slug         TEXT NOT NULL,
            name         TEXT NOT NULL,
            description  TEXT NOT NULL DEFAULT '',
            seo          TEXT NOT NULL DEFAULT '{}',
            PRIMARY KEY (site_id, slug)
        );
        CREATE TABLE tokens (
            id            INTEGER PRIMARY KEY,
            name          TEXT NOT NULL,
            hash          TEXT NOT NULL UNIQUE,
            site_id       INTEGER REFERENCES sites(id) ON DELETE CASCADE, -- NULL = token agenzia (tutti i siti)
            created_at    TEXT NOT NULL,
            last_used_at  TEXT,
            revoked_at    TEXT
        );
        CREATE TABLE publish_log (
            id          INTEGER PRIMARY KEY,
            site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            at          TEXT NOT NULL,
            actor       TEXT NOT NULL,
            message     TEXT NOT NULL,
            revision    TEXT,
            written     INTEGER NOT NULL DEFAULT 0,
            deleted     INTEGER NOT NULL DEFAULT 0,
            error       TEXT
        );
        SQL,
    ];

    public function __construct(string $path)
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
        $this->migrate();
    }

    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    public function run(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function insert(string $table, array $row): string
    {
        $cols = array_keys($row);
        $this->run(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols),
            implode(', ', array_map(fn($c) => ":$c", $cols))), $row);
        return $this->pdo->lastInsertId();
    }

    public function update(string $table, array $set, string $where, array $params): int
    {
        $assign = implode(', ', array_map(fn($c) => "$c = :set_$c", array_keys($set)));
        $bind = $params;
        foreach ($set as $c => $v) {
            $bind["set_$c"] = $v;
        }
        return $this->run("UPDATE $table SET $assign WHERE $where", $bind);
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function migrate(): void
    {
        $version = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        foreach (array_slice(self::MIGRATIONS, $version, null, true) as $i => $sql) {
            $this->transaction(function () use ($sql, $i) {
                $this->pdo->exec($sql);
                $this->pdo->exec('PRAGMA user_version = ' . ($i + 1));
            });
        }
    }
}
