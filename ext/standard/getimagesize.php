<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getimagesize() — image metadata from path (ext/standard/image.c; #3271).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/image.c PHP_FUNCTION(getimagesize)
 */
final class getimagesize extends Internal
{
    public function __construct()
    {
        parent::__construct('getimagesize');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('getimagesize() expects one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmStreamPath::coerceNonEmptyPathArg($frame->calledArgs[0], 'getimagesize', 0, 'filename');
        $imageinfo = null;
        if (2 === $argc) {
            $imageinfo = [];
        }
        $result = VmImage::getImageSize($filename, $imageinfo);
        if (false === $result) {
            if (!VmImage::pathPayloadReadable($filename)) {
                VmStreamOpenFailure::warnFailedToOpen($frame, 'getimagesize', $filename);
            }
            $frame->returnVar->bool(false);

            return;
        }
        if (2 === $argc) {
            VmImage::writeImageInfoVariable($frame->calledArgs[1], $imageinfo ?? []);
        }
        $frame->returnVar->array(VmImage::imageSizeResultToHashTable($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitGetimagesize::fromPath($context, ...$args);
    }
}
