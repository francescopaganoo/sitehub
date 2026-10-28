<?php
declare(strict_types=1);

namespace Panel;

use Panel\Http\ApiError;

/**
 * Immagini del blog di un sito.
 *
 * Caricamento: JPG, PNG, WebP o GIF fino a 15 MB. L'immagine viene raddrizzata (EXIF), ripulita dai metadati
 * (posizione GPS compresa: GD non li riscrive) e salvata in WebP:
 *   data/media/{sito}/{id}/master.webp   lato lungo al massimo 2400 px, base per i ritagli
 *   data/media/{sito}/{id}/{larghezza}.webp  480, 960, 1600 (solo quelle più piccole dell'originale)
 *
 * Finché un articolo è in bozza le immagini si vedono da SiteHub (/e/v1/{pk}/media/{id}/{larghezza}.webp);
 * alla pubblicazione vanno nel repo del sito, nello stesso commit dell'articolo (vedi Publisher).
 */
final class Media
{
    public const WIDTHS = [480, 960, 1600];
    public const MAX_BYTES = 15 * 1024 * 1024;
    private const MASTER = 2400;

    public function __construct(private Db $db, private string $root)
    {
    }

    public static function root(): string
    {
        return dirname(getenv('PANEL_DB') ?: dirname(__DIR__) . '/data/panel.sqlite') . '/media';
    }

    // ---------------------------------------------------------------- lettura

    public function all(int $siteId, string $q = ''): array
    {
        $rows = $this->db->all('SELECT * FROM media WHERE site_id = ? AND (? = \'\' OR name LIKE ? OR alt LIKE ?) ORDER BY created_at DESC',
            [$siteId, $q, "%$q%", "%$q%"]);
        return array_map(fn($r) => $this->decode($r), $rows);
    }

    public function get(int $siteId, string $id): array
    {
        $row = $this->db->one('SELECT * FROM media WHERE site_id = ? AND id = ?', [$siteId, $id]);
        return $row ? $this->decode($row) : throw ApiError::notFound('Immagine');
    }

    /** Percorso di un file su disco, null se non esiste. */
    public function file(int $siteId, string $id, string $variant): ?string
    {
        if (!preg_match('~^[a-f0-9]{12}$~', $id) || !preg_match('~^(master|\d{2,4})$~', $variant)) {
            return null;
        }
        $file = "$this->root/$siteId/$id/$variant.webp";
        return is_file($file) ? $file : null;
    }

    // ---------------------------------------------------------------- scrittura

    /** @param array{name:string, tmp_name:string, size:int, error:int} $upload elemento di $_FILES */
    public function upload(int $siteId, array $upload, string $alt): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ApiError::validation(['file' => match ($upload['error'] ?? null) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File troppo grande (massimo 15 MB)',
                UPLOAD_ERR_NO_FILE => 'Nessun file ricevuto',
                default => 'Caricamento non riuscito, riprova',
            }]);
        }
        if ($upload['size'] > self::MAX_BYTES) {
            throw ApiError::validation(['file' => 'File troppo grande (massimo 15 MB)']);
        }
        $data = (string) file_get_contents($upload['tmp_name']);
        $image = self::decodeImage($data);

        $sha = sha1($data);
        if ($same = $this->db->one('SELECT id FROM media WHERE site_id = ? AND sha1 = ? AND parent_id IS NULL', [$siteId, $sha])) {
            return $this->get($siteId, $same['id']); // stessa foto già caricata: si riusa
        }
        return $this->store($siteId, $image, Slug::make(pathinfo((string) $upload['name'], PATHINFO_FILENAME)) ?: 'immagine',
            trim(strip_tags($alt)), $sha, null, null);
    }

    /** Ritaglio dall'immagine originale: diventa una nuova immagine (l'originale resta per altri ritagli). */
    public function crop(int $siteId, string $id, array $rect): array
    {
        $source = $this->get($siteId, $id);
        $base = $source['parent_id'] ? $this->get($siteId, $source['parent_id']) : $source;
        $master = imagecreatefromwebp($this->file($siteId, $base['id'], 'master'));
        [$w, $h] = [imagesx($master), imagesy($master)];

        $x = max(0, (int) round($rect['x'] ?? 0));
        $y = max(0, (int) round($rect['y'] ?? 0));
        $cw = min($w - $x, (int) round($rect['width'] ?? 0));
        $ch = min($h - $y, (int) round($rect['height'] ?? 0));
        if ($cw < 50 || $ch < 50) {
            throw ApiError::validation(['crop' => "Ritaglio non valido: indica x, y, width, height in pixel dell'immagine originale ({$w}×{$h})"]);
        }
        $cut = imagecreatetruecolor($cw, $ch);
        self::keepAlpha($cut);
        imagecopy($cut, $master, 0, 0, $x, $y, $cw, $ch);

        return $this->store($siteId, $cut, $base['name'], $source['alt'], null, $base['id'],
            ['x' => $x, 'y' => $y, 'width' => $cw, 'height' => $ch]);
    }

    public function updateAlt(int $siteId, string $id, string $alt): array
    {
        $this->get($siteId, $id);
        $this->db->run('UPDATE media SET alt = ? WHERE site_id = ? AND id = ?', [trim(strip_tags($alt)), $siteId, $id]);
        return $this->get($siteId, $id);
    }

    public function delete(int $siteId, string $id): void
    {
        $this->get($siteId, $id);
        $this->db->run('DELETE FROM media WHERE site_id = ? AND id = ?', [$siteId, $id]);
        $dir = "$this->root/$siteId/$id";
        foreach (glob("$dir/*.webp") ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    // ---------------------------------------------------------------- elaborazione

    private function store(int $siteId, \GdImage $image, string $name, string $alt, ?string $sha, ?string $parentId, ?array $crop): array
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        if (max($w, $h) > self::MASTER) {
            $scale = self::MASTER / max($w, $h);
            $image = self::resize($image, (int) round($w * $scale), (int) round($h * $scale));
            [$w, $h] = [imagesx($image), imagesy($image)];
        }

        $id = bin2hex(random_bytes(6));
        $dir = "$this->root/$siteId/$id";
        mkdir($dir, 0775, true);
        imagewebp($image, "$dir/master.webp", 90);
        foreach (self::widths($w) as $vw) {
            $variant = $vw === $w ? $image : self::resize($image, $vw, (int) round($h * $vw / $w));
            imagewebp($variant, "$dir/$vw.webp", 80);
        }

        $this->db->insert('media', [
            'id' => $id, 'site_id' => $siteId, 'name' => mb_substr($name, 0, 60), 'alt' => $alt, 'width' => $w, 'height' => $h,
            'bytes' => (int) filesize("$dir/" . max(self::widths($w)) . '.webp'), 'sha1' => $sha, 'parent_id' => $parentId,
            'crop' => $crop ? json_encode($crop) : null, 'created_at' => date('c'),
        ]);
        return $this->get($siteId, $id);
    }

    /** Larghezze prodotte: quelle standard più piccole dell'immagine, più l'immagine intera se è sotto i 1600 px. */
    public static function widths(int $width): array
    {
        $out = array_values(array_filter(self::WIDTHS, fn($w) => $w < $width));
        if ($width <= max(self::WIDTHS)) {
            $out[] = $width;
        }
        return $out;
    }

    private static function decodeImage(string $data): \GdImage
    {
        $info = @getimagesizefromstring($data);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $heic = str_contains(substr($data, 4, 16), 'ftyphei') || str_contains(substr($data, 4, 16), 'ftypmif');
            throw ApiError::validation(['file' => $heic
                ? 'Le foto HEIC dell\'iPhone non sono supportate: esportala in JPG (o imposta la fotocamera su "Più compatibile")'
                : 'Formato non supportato: usa JPG, PNG, WebP o GIF']);
        }
        if (($info[0] ?? 0) * ($info[1] ?? 0) > 50_000_000) {
            throw ApiError::validation(['file' => 'Immagine troppo grande (oltre 50 megapixel)']);
        }
        $image = @imagecreatefromstring($data) ?: throw ApiError::validation(['file' => 'Immagine danneggiata o non leggibile']);
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        self::keepAlpha($image);

        // foto del telefono: l'orientamento è nei dati EXIF, i pixel sono "di lato"
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
            $image = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
        }
        return $image;
    }

    private static function resize(\GdImage $image, int $w, int $h): \GdImage
    {
        $out = imagecreatetruecolor($w, $h);
        self::keepAlpha($out);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image));
        return $out;
    }

    private static function keepAlpha(\GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    private function decode(array $row): array
    {
        $w = (int) $row['width'];
        return [
            'id'        => $row['id'],
            'name'      => $row['name'],
            'alt'       => $row['alt'],
            'width'     => $w,
            'height'    => (int) $row['height'],
            'widths'    => self::widths($w),
            'bytes'     => (int) $row['bytes'],
            'parent_id' => $row['parent_id'],
            'crop'      => $row['crop'] ? json_decode($row['crop'], true) : null,
            'created_at' => $row['created_at'],
        ];
    }
}
