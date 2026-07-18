<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * GD IMG_* constants (php-src ext/gd/gd.c, ext/gd/php_gd.h).
 */
final class GdConstants
{
    /** @var array<string, int> */
    public const REGISTERED = [
        'IMG_FILTER_NEGATE' => 0,
        'IMG_FILTER_GRAYSCALE' => 1,
        'IMG_FILTER_BRIGHTNESS' => 2,
        'IMG_FILTER_CONTRAST' => 3,
        'IMG_FILTER_COLORIZE' => 4,
        'IMG_FILTER_EDGEDETECT' => 5,
        'IMG_FILTER_EMBOSS' => 6,
        'IMG_FILTER_GAUSSIAN_BLUR' => 7,
        'IMG_FILTER_SELECTIVE_BLUR' => 8,
        'IMG_FILTER_MEAN_REMOVAL' => 9,
        'IMG_FILTER_SMOOTH' => 10,
        'IMG_FILTER_PIXELATE' => 11,
        'IMG_FILTER_SCATTER' => 12,
        'IMG_FLIP_HORIZONTAL' => 1,
        'IMG_FLIP_VERTICAL' => 2,
        'IMG_FLIP_BOTH' => 3,
        'IMG_CROP_DEFAULT' => 0,
        'IMG_CROP_TRANSPARENT' => 1,
        'IMG_CROP_BLACK' => 2,
        'IMG_CROP_WHITE' => 3,
        'IMG_CROP_SIDES' => 4,
        'IMG_CROP_THRESHOLD' => 5,
        // gdInterpolationMethod (php-src ext/gd/libgd/gd.h; #20405)
        'IMG_DEFAULT' => 0,
        'IMG_BELL' => 1,
        'IMG_BESSEL' => 2,
        'IMG_BILINEAR_FIXED' => 3,
        'IMG_BICUBIC' => 4,
        'IMG_BICUBIC_FIXED' => 5,
        'IMG_BLACKMAN' => 6,
        'IMG_BOX' => 7,
        'IMG_BSPLINE' => 8,
        'IMG_CATMULLROM' => 9,
        'IMG_GAUSSIAN' => 10,
        'IMG_GENERALIZED_CUBIC' => 11,
        'IMG_HERMITE' => 12,
        'IMG_HAMMING' => 13,
        'IMG_HANNING' => 14,
        'IMG_MITCHELL' => 15,
        'IMG_NEAREST_NEIGHBOUR' => 16,
        'IMG_POWER' => 17,
        'IMG_QUADRATIC' => 18,
        'IMG_SINC' => 19,
        'IMG_TRIANGLE' => 20,
        'IMG_WEIGHTED4' => 21,
        // gdEffect* layer modes (php-src ext/gd/libgd/gd.h; #20429)
        'IMG_EFFECT_REPLACE' => 0,
        'IMG_EFFECT_ALPHABLEND' => 1,
        'IMG_EFFECT_NORMAL' => 2,
        'IMG_EFFECT_OVERLAY' => 3,
        'IMG_EFFECT_MULTIPLY' => 4,
        // gdAffineStandardMatrix (php-src ext/gd/libgd/gd.h; #20441)
        'IMG_AFFINE_TRANSLATE' => 0,
        'IMG_AFFINE_SCALE' => 1,
        'IMG_AFFINE_ROTATE' => 2,
        'IMG_AFFINE_SHEAR_HORIZONTAL' => 3,
        'IMG_AFFINE_SHEAR_VERTICAL' => 4,
        // gdArc style flags (php-src ext/gd/libgd/gd.h; #20437)
        'IMG_ARC_ROUNDED' => 0,
        'IMG_ARC_PIE' => 0,
        'IMG_ARC_CHORD' => 1,
        'IMG_ARC_NOFILL' => 2,
        'IMG_ARC_EDGED' => 4,
        // Special drawing colors (php-src ext/gd/libgd/gd.h; #20439)
        'IMG_COLOR_STYLED' => -2,
        'IMG_COLOR_BRUSHED' => -3,
        'IMG_COLOR_STYLEDBRUSHED' => -4,
        'IMG_COLOR_TILED' => -5,
        'IMG_COLOR_TRANSPARENT' => -6,
        // Image type bitflags for imagetypes() (php-src ext/gd/php_gd.h; #20471)
        'IMG_GIF' => 1,
        'IMG_JPG' => 2,
        'IMG_JPEG' => 2,
        'IMG_PNG' => 4,
        'IMG_WBMP' => 8,
        'IMG_XPM' => 16,
        'IMG_WEBP' => 32,
        'IMG_BMP' => 64,
        'IMG_TGA' => 128,
        'IMG_AVIF' => 256,
    ];

    /** gdArc / IMG_ARC_PIE — rounded pie edge (php-src gd.h; #20437). */
    public const ARC_PIE = 0;

    /** gdChord / IMG_ARC_CHORD. */
    public const ARC_CHORD = 1;

    /** gdNoFill / IMG_ARC_NOFILL. */
    public const ARC_NOFILL = 2;

    /** gdEdged / IMG_ARC_EDGED. */
    public const ARC_EDGED = 4;

    /** gdStyled / IMG_COLOR_STYLED (#20439). */
    public const COLOR_STYLED = -2;

    /** gdBrushed / IMG_COLOR_BRUSHED (#20439). */
    public const COLOR_BRUSHED = -3;

    /** gdStyledBrushed / IMG_COLOR_STYLEDBRUSHED (#20439). */
    public const COLOR_STYLEDBRUSHED = -4;

    /** gdTiled / IMG_COLOR_TILED (#20439). */
    public const COLOR_TILED = -5;

    /** gdTransparent / IMG_COLOR_TRANSPARENT (#20439). */
    public const COLOR_TRANSPARENT = -6;

    /** GD_METHOD_COUNT — exclusive upper bound for imagescale() $mode (#20405). */
    public const INTERPOLATION_METHOD_COUNT = 22;
}
