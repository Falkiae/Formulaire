<?php

declare(strict_types=1);

namespace Keepnew\Support;

/**
 * Traitement sécurisé des photos uploadées (app technicien).
 *
 * Sécurité (exigences du cahier des charges) :
 *  - vérification du type MIME RÉEL (pas de l'extension ni du Content-Type client) ;
 *  - taille maximale ;
 *  - RÉ-ENCODAGE de l'image via GD (neutralise tout contenu malveillant) ;
 *  - stockage HORS webroot, nom de fichier aléatoire.
 */
final class ImageUpload
{
    private const MAX_BYTES = 12 * 1024 * 1024; // 12 Mo
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly string $storageDir)
    {
    }

    /**
     * Valide et ré-encode un fichier uploadé ; renvoie le chemin relatif stocké.
     *
     * @param array{name:string, type:string, tmp_name:string, error:int, size:int} $file
     * @throws \RuntimeException si le fichier est invalide
     */
    public function store(array $file, string $subdir = 'photos'): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Échec de l\'upload.');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('Fichier trop volumineux.');
        }

        // Type MIME réel via finfo (jamais le Content-Type client).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new \RuntimeException('Format d\'image non autorisé.');
        }

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            'image/png' => imagecreatefrompng($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
            default => false,
        };
        if ($image === false) {
            throw new \RuntimeException('Image illisible.');
        }

        $dir = rtrim($this->storageDir, '/') . '/' . trim($subdir, '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = date('Y/m/') . bin2hex(random_bytes(16)) . '.jpg';
        $fullDir = $dir . '/' . dirname($name);
        if (!is_dir($fullDir)) {
            @mkdir($fullDir, 0775, true);
        }
        $target = $dir . '/' . $name;

        // Ré-encodage systématique en JPEG (neutralise tout payload).
        imagejpeg($image, $target, 85);
        imagedestroy($image);

        return trim($subdir, '/') . '/' . $name;
    }
}
