<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmImage;

/**
 * GdImage payload for {@see VmGd} (php-src ext/gd/gd.c; #6215, #3496).
 *
 * Decode path stores pre-encoded PNG bytes; drawing path stores a truecolor raster.
 */
final class GdImageState
{
    public string $encoded;

    public int $imageType;

    public int $width;

    public int $height;

    /** @var list<int> */
    public array $pixels;

    public bool $truecolor;

    /**
     * @param list<int> $pixels
     */
    private function __construct(
        string $encoded,
        int $imageType,
        int $width,
        int $height,
        array $pixels,
        bool $truecolor
    ) {
        $this->encoded = $encoded;
        $this->imageType = $imageType;
        $this->width = $width;
        $this->height = $height;
        $this->pixels = $pixels;
        $this->truecolor = $truecolor;
    }

    public static function fromEncoded(string $encoded, int $imageType): self
    {
        return new self($encoded, $imageType, 0, 0, [], false);
    }

    public static function createTruecolor(int $width, int $height): self
    {
        $pixels = array_fill(0, $width * $height, 0);

        return new self('', VmImage::IMAGETYPE_PNG, $width, $height, $pixels, true);
    }

    /**
     * @param list<int> $pixels
     */
    public static function fromRaster(int $width, int $height, array $pixels): self
    {
        return new self('', VmImage::IMAGETYPE_PNG, $width, $height, $pixels, true);
    }

    public function hasRaster(): bool
    {
        return $this->truecolor && $this->width > 0 && $this->height > 0;
    }

    public function hasEncoded(): bool
    {
        return '' !== $this->encoded;
    }
}
