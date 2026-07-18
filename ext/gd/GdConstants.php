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
    ];

    /** GD_METHOD_COUNT — exclusive upper bound for imagescale() $mode (#20405). */
    public const INTERPOLATION_METHOD_COUNT = 22;
}
