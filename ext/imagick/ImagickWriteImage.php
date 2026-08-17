<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\Frame;

/** Imagick::writeImage(string $filename): bool — VM (#6235). */
final class ImagickWriteImage extends ImagickClassMethod
{
    public function __construct()
    {
        parent::__construct('writeImage');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Imagick::writeImage()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Imagick::writeImage() expects at least 1 argument, 0 given');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'Imagick::writeImage', 1, 'filename');
        $ok = VmImagick::writeImage($receiver, $filename);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
