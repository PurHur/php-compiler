<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPLLVM\Value;

/**
 * exif_thumbnail() — embedded EXIF thumbnail bytes (ext/exif/exif.c; #20027).
 *
 * @see https://github.com/php/php-src/blob/master/ext/exif/exif.c PHP_FUNCTION(exif_thumbnail)
 */
final class exif_thumbnail extends Internal
{
    public function __construct()
    {
        parent::__construct('exif_thumbnail');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'exif_thumbnail() expects at least 1 argument and at most 4, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 0, 'exif_thumbnail', 'file');
        $width = null;
        $height = null;
        $imageType = null;
        $data = VmExifRead::thumbnail($filename, $width, $height, $imageType);
        if ($argc >= 2) {
            $out = $frame->calledArgs[1]->byRefTarget();
            if (null === $width) {
                $out->null();
            } else {
                $out->int($width);
            }
        }
        if ($argc >= 3) {
            $out = $frame->calledArgs[2]->byRefTarget();
            if (null === $height) {
                $out->null();
            } else {
                $out->int($height);
            }
        }
        if ($argc >= 4) {
            $out = $frame->calledArgs[3]->byRefTarget();
            if (null === $imageType) {
                $out->null();
            } else {
                $out->int($imageType);
            }
        }
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('exif_thumbnail() is not implemented for JIT in this compiler build (issue #20027)');
    }
}
