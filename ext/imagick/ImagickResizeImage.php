<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Imagick::resizeImage(int $columns, int $rows, int $filter = 0, float $blur = 1, bool $bestfit = false): bool — VM (#6235). */
final class ImagickResizeImage extends ImagickClassMethod
{
    public function __construct()
    {
        parent::__construct('resizeImage');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Imagick::resizeImage()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('Imagick::resizeImage() expects at least 2 arguments, 0 given');
        }
        $columns = $this->intArg($frame->calledArgs[1], 'Imagick::resizeImage', 1, 'columns');
        $rows = $this->intArg($frame->calledArgs[2], 'Imagick::resizeImage', 2, 'rows');
        $filter = \count($frame->calledArgs) >= 4
            ? $this->intArg($frame->calledArgs[3], 'Imagick::resizeImage', 3, 'filter')
            : 0;
        $blur = \count($frame->calledArgs) >= 5
            ? $this->floatArg($frame->calledArgs[4], 'Imagick::resizeImage', 4, 'blur', 1.0)
            : 1.0;
        $bestfit = \count($frame->calledArgs) >= 6
            ? $this->boolArg($frame->calledArgs[5], 'Imagick::resizeImage', 5, 'bestfit', false)
            : false;
        $ok = VmImagick::resizeImage($receiver, $columns, $rows, $filter, $blur, $bestfit);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
