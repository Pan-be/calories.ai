<?php

namespace App\Photo;

use App\Http\ApiError;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Meal photos on disk. Every upload is decoded and re-encoded as JPEG (turned upright by
 * its EXIF orientation, at most 1600 px, plus a 400 px thumbnail), so no EXIF data such
 * as GPS position is ever stored or sent on.
 */
final class PhotoStore
{
    private const MAX_SIDE = 1600;
    private const THUMB_SIDE = 400;
    private const MAX_PIXELS = 40_000_000;
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(#[Autowire(env: 'resolve:KALORIE_PHOTO_DIR')] private string $dir)
    {
    }

    /** Store an upload; returns the photo id. */
    public function store(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw ApiError::invalid($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE
                ? 'Zdjęcie jest za duże (najwyżej 20 MB).' : 'Nie udało się wysłać zdjęcia.');
        }
        $path = $file->getPathname();
        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], self::TYPES, true)) {
            $head = (string) file_get_contents($path, false, null, 0, 16);
            throw ApiError::invalid(str_contains($head, 'ftyphei') || str_contains($head, 'ftypmif')
                ? 'Zdjęcia HEIC nie są obsługiwane. Wyślij JPEG albo zrób zdjęcie z poziomu aplikacji.'
                : 'To nie jest zdjęcie w obsługiwanym formacie (JPEG, PNG, WebP).');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw ApiError::invalid('Zdjęcie ma za dużą rozdzielczość.');
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        };
        if ($image === false) {
            throw ApiError::invalid('Nie da się odczytać tego zdjęcia.');
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->upright($image, $path);
        }

        $id = bin2hex(random_bytes(16));
        $target = $this->path($id, false);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Cannot create photo directory ' . dirname($target));
        }
        $this->save($image, self::MAX_SIDE, $target, 85);
        $this->save($image, self::THUMB_SIDE, $this->path($id, true), 80);
        return $id;
    }

    public function bytes(string $id): string
    {
        $data = @file_get_contents($this->path($id, false));
        return $data === false ? throw new \RuntimeException("Photo $id is missing") : $data;
    }

    public function path(string $id, bool $thumb): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            throw new \InvalidArgumentException('Bad photo id');
        }
        return sprintf('%s/%s/%s%s.jpg', rtrim($this->dir, '/'), substr($id, 0, 2), $id, $thumb ? '_t' : '');
    }

    public function delete(string $id): void
    {
        foreach ([false, true] as $thumb) {
            $path = $this->path($id, $thumb);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function upright(\GdImage $image, string $path): \GdImage
    {
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270, // imagerotate turns counter-clockwise
            7, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        return imagerotate($image, $angle, 0);
    }

    private function save(\GdImage $image, int $maxSide, string $target, int $quality): void
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $maxSide / max($w, $h));
        $out = $scale < 1 ? imagescale($image, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC) : $image;
        // PNG/WebP transparency would turn black in JPEG: put it on white first.
        $flat = imagecreatetruecolor(imagesx($out), imagesy($out));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $out, 0, 0, 0, 0, imagesx($out), imagesy($out));
        imageinterlace($flat, true);
        if (!imagejpeg($flat, $target, $quality)) {
            throw new \RuntimeException("Cannot write $target");
        }
    }
}
