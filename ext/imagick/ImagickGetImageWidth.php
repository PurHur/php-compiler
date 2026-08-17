<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\Frame;

/** Imagick::getImageWidth(): int — VM (#6235). */
final class ImagickGetImageWidth extends ImagickClassMethod
{
    public function __construct()
    {
        parent::__construct('getImageWidth');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Imagick::getImageWidth()');
        $width = VmImagick::getImageWidth($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($width);
        }
    }
}
