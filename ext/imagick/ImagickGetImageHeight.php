<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\Frame;

/** Imagick::getImageHeight(): int — VM (#6235). */
final class ImagickGetImageHeight extends ImagickClassMethod
{
    public function __construct()
    {
        parent::__construct('getImageHeight');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Imagick::getImageHeight()');
        $height = VmImagick::getImageHeight($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($height);
        }
    }
}
