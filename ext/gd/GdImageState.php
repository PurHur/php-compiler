<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmImage;

/**
 * GdImage payload for {@see VmGd} (php-src ext/gd/gd.c; #6215, #3496, #20415).
 *
 * Decode path stores pre-encoded PNG bytes; drawing path stores a raster
 * (truecolor ARGB ints, or palette indices + {@see $colors}).
 */
final class GdImageState
{
    public string $encoded;

    public int $imageType;

    public int $width;

    public int $height;

    /** @var list<int> truecolor ARGB or palette indices */
    public array $pixels;

    public bool $truecolor;

    /**
     * Palette RGB entries (opaque ARGB with alpha 0) when !$truecolor.
     *
     * @var list<int>
     */
    public array $colors;

    /**
     * libgd alphaBlendingFlag — default on for truecolor (php-src ext/gd/libgd/gd.c; #6535).
     */
    public bool $alphaBlending;

    /**
     * libgd saveAlphaFlag — default off (php-src ext/gd/libgd/gd.c; #6535).
     */
    public bool $saveAlpha;

    /**
     * libgd thick — line stroke width, default 1 (php-src ext/gd/libgd/gd.c; #20406).
     */
    public int $thick;

    /**
     * libgd AA — when true, imageline uses gdImageAALine (php-src ext/gd/gd.c imageantialias; #20406).
     */
    public bool $antiAlias;

    /**
     * gdInterpolationMethod — default GD_BILINEAR_FIXED (php-src ext/gd/libgd/gd.c; #20416).
     */
    public int $interpolationId;

    /**
     * @param list<int> $pixels
     * @param list<int> $colors
     */
    private function __construct(
        string $encoded,
        int $imageType,
        int $width,
        int $height,
        array $pixels,
        bool $truecolor,
        array $colors = [],
        bool $alphaBlending = true,
        bool $saveAlpha = false,
        int $thick = 1,
        bool $antiAlias = false,
        int $interpolationId = 3
    ) {
        $this->encoded = $encoded;
        $this->imageType = $imageType;
        $this->width = $width;
        $this->height = $height;
        $this->pixels = $pixels;
        $this->truecolor = $truecolor;
        $this->colors = $colors;
        $this->alphaBlending = $alphaBlending;
        $this->saveAlpha = $saveAlpha;
        $this->thick = $thick;
        $this->antiAlias = $antiAlias;
        $this->interpolationId = $interpolationId;
    }

    public static function fromEncoded(string $encoded, int $imageType): self
    {
        return new self($encoded, $imageType, 0, 0, [], false, [], true, false);
    }

    public static function createTruecolor(int $width, int $height): self
    {
        $pixels = array_fill(0, $width * $height, 0);

        return new self('', VmImage::IMAGETYPE_PNG, $width, $height, $pixels, true, [], true, false);
    }

    public static function createPalette(int $width, int $height): self
    {
        $pixels = array_fill(0, $width * $height, 0);

        // Palette canvases start with alpha blending off (php-src gdImageCreate).
        return new self('', VmImage::IMAGETYPE_PNG, $width, $height, $pixels, false, [], false, false);
    }

    /**
     * @param list<int> $pixels
     */
    public static function fromRaster(int $width, int $height, array $pixels): self
    {
        return new self('', VmImage::IMAGETYPE_PNG, $width, $height, $pixels, true, [], true, false);
    }

    public function hasRaster(): bool
    {
        return $this->width > 0 && $this->height > 0;
    }

    public function hasEncoded(): bool
    {
        return '' !== $this->encoded;
    }
}
