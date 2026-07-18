<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmImage;
use PHPCompiler\VM\ObjectEntry;

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
     * libgd alphaBlendingFlag — gdEffect* int (php-src ext/gd/libgd/gd.h; #6535, #20429).
     * Default gdEffectAlphaBlend (1) for truecolor; 0 for palette.
     */
    public int $alphaBlending;

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
     * libgd res_x / res_y DPI (GD_RESOLUTION=96 default; php-src ext/gd/libgd/gd.h; #20430).
     */
    public int $resX;

    public int $resY;

    /**
     * libgd transparent color index / truecolor value (−1 = none; php-src; #20459).
     */
    public int $transparent;

    /**
     * libgd style[] / styleLength / stylePos for IMG_COLOR_STYLED (#20439).
     *
     * @var list<int>|null
     */
    public ?array $style = null;

    public int $stylePos = 0;

    /**
     * libgd brush image for IMG_COLOR_BRUSHED (#20439).
     */
    public ?ObjectEntry $brush = null;

    /**
     * Palette brush → dest color map when both are palette (#20439).
     *
     * @var array<int, int>
     */
    public array $brushColorMap = [];

    /**
     * libgd interlace flag (php-src gdImageInterlace; #20460).
     */
    public bool $interlace = false;

    /**
     * libgd clip rectangle cx1,cy1,cx2,cy2 (inclusive; #20460).
     */
    public int $cx1 = 0;

    public int $cy1 = 0;

    public int $cx2 = 0;

    public int $cy2 = 0;

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
        int $alphaBlending = 1,
        bool $saveAlpha = false,
        int $thick = 1,
        bool $antiAlias = false,
        int $interpolationId = 3,
        int $resX = 96,
        int $resY = 96,
        int $transparent = -1
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
        $this->resX = $resX;
        $this->resY = $resY;
        $this->transparent = $transparent;
        if ($width > 0 && $height > 0) {
            $this->cx2 = $width - 1;
            $this->cy2 = $height - 1;
        }
    }

    public static function fromEncoded(string $encoded, int $imageType): self
    {
        return new self($encoded, $imageType, 0, 0, [], false, [], 1, false);
    }

    public static function createTruecolor(int $width, int $height): self
    {
        $pixels = array_fill(0, $width * $height, 0);

        return new self('', VmImage::IMAGETYPE_PNG, $width, $height, $pixels, true, [], 1, false);
    }

    public static function createPalette(int $width, int $height): self
    {
        $pixels = array_fill(0, $width * $height, 0);

        // Palette canvases start with alpha blending off (php-src gdImageCreate).
        return new self('', VmImage::IMAGETYPE_PNG, $width, $height, $pixels, false, [], 0, false);
    }

    /**
     * @param list<int> $pixels
     */
    public static function fromRaster(int $width, int $height, array $pixels): self
    {
        return new self('', VmImage::IMAGETYPE_PNG, $width, $height, $pixels, true, [], 1, false);
    }

    /**
     * Build raster state from a decoded GD1/GD2 payload (#20502).
     *
     * @param list<int> $pixels
     * @param list<int> $colors
     */
    public static function fromGdDecoded(
        int $width,
        int $height,
        bool $truecolor,
        array $pixels,
        array $colors,
        int $transparent
    ): self {
        $state = new self(
            '',
            VmImage::IMAGETYPE_PNG,
            $width,
            $height,
            $pixels,
            $truecolor,
            $colors,
            $truecolor ? 1 : 0,
            false,
            1,
            false,
            3,
            96,
            96,
            $transparent
        );

        return $state;
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
