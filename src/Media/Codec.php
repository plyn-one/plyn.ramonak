<?php
declare(strict_types=1);

namespace Ramonak\Media;

/** Turns an image of any common format into the one form a site keeps and sends: AVIF, upright, without metadata, in sRGB. */
interface Codec
{
    /**
     * The image scaled down (never up) to fit maxWidth and maxSide on its longer side, as AVIF.
     * @return array{0:string,1:int,2:int} its bytes, width and height
     */
    public function avif(string $data, int $maxWidth, int $maxSide, int $quality): array;
}
