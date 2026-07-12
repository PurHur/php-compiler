<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmImage;
use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPLLVM\Value;

/**
 * exif_imagetype() — image type from path (ext/exif/exif.c; #3400).
 *
 * @see https://github.com/php/php-src/blob/master/ext/exif/exif.c PHP_FUNCTION(exif_imagetype)
 */
final class exif_imagetype extends Internal
{
    public function __construct()
    {
        parent::__construct('exif_imagetype');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'exif_imagetype() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 0, 'exif_imagetype', 'filename');
        $type = VmExifRead::imageType($filename);
        if (false === $type) {
            if (!VmImage::pathPayloadReadable($filename)) {
                VmStreamOpenFailure::warnFailedToOpen($frame, 'exif_imagetype', $filename);
            }
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($type);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitExifImagetype::invoke($context, $args);
    }
}
