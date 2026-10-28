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
        // moduli collegati all'HTML tramite chiave pubblica: form, email per sito
        <<<SQL
        ALTER TABLE sites ADD COLUMN public_key TEXT;
        UPDATE sites SET public_key = 'pk_' || lower(hex(randomblob(12)));
        CREATE UNIQUE INDEX sites_public_key ON sites(public_key);
        ALTER TABLE sites ADD COLUMN modules TEXT NOT NULL DEFAULT '{"forms":true,"cookies":true,"blog":true}';
        ALTER TABLE sites ADD COLUMN origins TEXT NOT NULL DEFAULT '[]'; -- domini extra ammessi (staging, anteprime)
        CREATE TABLE mail_settings (
            site_id     INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
            host        TEXT NOT NULL,
            port        INTEGER NOT NULL,
            encryption  TEXT NOT NULL,              -- tls (STARTTLS) | ssl | none
            username    TEXT NOT NULL DEFAULT '',
            password    TEXT NOT NULL DEFAULT '',   -- cifrata (Crypto)
            from_email  TEXT NOT NULL,
            from_name   TEXT NOT NULL DEFAULT '',
            recipients  TEXT NOT NULL DEFAULT '[]', -- destinatari predefiniti dei form
            updated_at  TEXT NOT NULL
        );
        CREATE TABLE forms (
            site_id          INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            key              TEXT NOT NULL,
            name             TEXT NOT NULL,
            enabled          INTEGER NOT NULL DEFAULT 1,
            recipients       TEXT NOT NULL DEFAULT '[]', -- vuoto = destinatari predefiniti del sito
            subject          TEXT NOT NULL DEFAULT '',
            success_message  TEXT NOT NULL DEFAULT '',
            autoreply        TEXT NOT NULL DEFAULT '{}', -- {enabled, subject, body}
            created_at       TEXT NOT NULL,
            PRIMARY KEY (site_id, key)
        );
        CREATE TABLE submissions (
            id          INTEGER PRIMARY KEY,
            site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            form_key    TEXT NOT NULL,
            fields      TEXT NOT NULL,              -- [{name, label, type, value}]
            reply_to    TEXT,
            page        TEXT NOT NULL DEFAULT '',
            ip_hash     TEXT NOT NULL,
            user_agent  TEXT NOT NULL DEFAULT '',
            status      TEXT NOT NULL,              -- pending | sent | failed | spam
            attempts    INTEGER NOT NULL DEFAULT 0,
            last_error  TEXT,
            created_at  TEXT NOT NULL,
            sent_at     TEXT
        );
        CREATE INDEX submissions_site ON submissions(site_id, id DESC);
        CREATE INDEX submissions_rate ON submissions(site_id, ip_hash, created_at);
        SQL,
        // account SMTP riutilizzabili: un sito usa un account oppure credenziali proprie
        <<<SQL
        CREATE TABLE smtp_accounts (
            id          INTEGER PRIMARY KEY,
            name        TEXT NOT NULL UNIQUE,
            host        TEXT NOT NULL,
            port        INTEGER NOT NULL,
            encryption  TEXT NOT NULL,
            username    TEXT NOT NULL DEFAULT '',
            password    TEXT NOT NULL DEFAULT '',   -- cifrata (Crypto)
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL
        );
        ALTER TABLE mail_settings ADD COLUMN smtp_account_id INTEGER REFERENCES smtp_accounts(id);
        SQL,
        // immagini del blog (file in data/media/{sito}/{id}/, vedi Media)
        <<<SQL
        CREATE TABLE media (
            id          TEXT PRIMARY KEY,           -- 12 caratteri esadecimali
            site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            name        TEXT NOT NULL,              -- dal nome del file: entra nel nome pubblicato
            alt         TEXT NOT NULL DEFAULT '',
            width       INTEGER NOT NULL,
            height      INTEGER NOT NULL,
            bytes       INTEGER NOT NULL,
            sha1        TEXT,                       -- del file caricato: la stessa foto non si duplica
            parent_id   TEXT,                       -- ritaglio di un'altra immagine
            crop        TEXT,                       -- JSON {x, y, width, height} sull'originale
            created_at  TEXT NOT NULL
        );
        CREATE INDEX media_site ON media(site_id, created_at DESC);
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
