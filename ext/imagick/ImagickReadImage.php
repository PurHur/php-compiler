<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Imagick::readImage(string $filename): bool — VM (#6235). */
final class ImagickReadImage extends ImagickClassMethod
{
    public function __construct()
    {
        parent::__construct('readImage');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Imagick::readImage()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Imagick::readImage() expects at least 1 argument, 0 given');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'Imagick::readImage', 1, 'filename');
        $ok = VmImagick::readImage($receiver, $filename);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
