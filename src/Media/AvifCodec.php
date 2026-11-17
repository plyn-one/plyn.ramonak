<?php
declare(strict_types=1);

namespace Ramonak\Media;

use Ramonak\OperationError;

/**
 * Imagick reads what the host can read (JPEG, PNG, WebP, AVIF, GIF, HEIC), turns the image upright by its EXIF, strips every
 * metadata (EXIF, GPS, XMP, ICC once in sRGB) and scales it; GD writes AVIF, which Imagick on shared hosts often cannot. Without
 * Imagick, GD reads alone and an orientation in EXIF is lost. Without AVIF in GD the codec refuses: nothing else is kept.
 */
final class AvifCodec implements Codec
{
    public static function usable(): bool
    {
        return function_exists('imageavif');
    }

    public function avif(string $data, int $maxWidth, int $maxSide, int $quality): array
    {
        if (!self::usable()) {
            throw new OperationError('media_avif_unavailable', 'This host cannot write AVIF (GD without AVIF)', 503);
        }
        $gd = extension_loaded('imagick') ? $this->throughImagick($data, $maxWidth, $maxSide) : $this->throughGd($data, $maxWidth, $maxSide);
        imagesavealpha($gd, true);
        ob_start();
        $ok = imageavif($gd, null, max(0, min(100, $quality)), 6);
        $bytes = (string) ob_get_clean();
        if (!$ok || $bytes === '') {
            throw new OperationError('media_encode_failed', 'The image could not be written as AVIF', 500);
        }
        return [$bytes, imagesx($gd), imagesy($gd)];
    }

    private function throughImagick(string $data, int $maxWidth, int $maxSide): \GdImage
    {
        try {
            $im = new \Imagick();
            $im->readImageBlob($data);
        } catch (\ImagickException) {
            throw new OperationError('media_unreadable', 'The file is not an image this host can read');
        }
        if ($im->getNumberImages() > 1) {
            $im->setIteratorIndex(0);
            $im = $im->getImage(); // the first frame of an animation
        }
        $this->upright($im);
        $im->stripImage();
        try {
            $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        } catch (\ImagickException) {
        }
        [$w, $h] = self::fit($im->getImageWidth(), $im->getImageHeight(), $maxWidth, $maxSide);
        if ($w !== $im->getImageWidth()) {
            $im->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
        }
        $im->setImageFormat('png');
        $gd = imagecreatefromstring($im->getImageBlob());
        $im->clear();
        return $gd ?: throw new OperationError('media_unreadable', 'The image could not be prepared');
    }

    private function upright(\Imagick $im): void
    {
        if (method_exists($im, 'autoOrient')) {
            $im->autoOrient();
            return;
        }
        $o = $im->getImageOrientation();
        match ($o) {
            \Imagick::ORIENTATION_BOTTOMRIGHT => $im->rotateImage('#000', 180),
            \Imagick::ORIENTATION_RIGHTTOP => $im->rotateImage('#000', 90),
            \Imagick::ORIENTATION_LEFTBOTTOM => $im->rotateImage('#000', -90),
            \Imagick::ORIENTATION_TOPRIGHT => $im->flopImage(),
            \Imagick::ORIENTATION_BOTTOMLEFT => $im->flipImage(),
            default => null,
        };
        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    private function throughGd(string $data, int $maxWidth, int $maxSide): \GdImage
    {
        $gd = @imagecreatefromstring($data) ?: throw new OperationError('media_unreadable', 'The file is not an image this host can read');
        [$w, $h] = self::fit(imagesx($gd), imagesy($gd), $maxWidth, $maxSide);
        return $w === imagesx($gd) ? $gd : (imagescale($gd, $w, $h, IMG_BICUBIC) ?: $gd);
    }

    /** @return array{int,int} the size that fits, never larger than the image */
    public static function fit(int $w, int $h, int $maxWidth, int $maxSide): array
    {
        $scale = min(1.0, $maxWidth / max(1, $w), $maxSide / max(1, $w, $h));
        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    }
}
