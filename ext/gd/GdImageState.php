<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * Encoded image payload for {@see VmGd} GdImage instances (php-src ext/gd/gd.c; #6215).
 */
final class GdImageState
{
    public string $encoded;

    public int $imageType;

    public function __construct(string $encoded, int $imageType)
    {
        $this->encoded = $encoded;
        $this->imageType = $imageType;
    }
}
