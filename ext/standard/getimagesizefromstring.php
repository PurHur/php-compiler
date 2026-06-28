<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getimagesizefromstring() — image metadata from bytes (ext/standard/image.c; #3271).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/image.c PHP_FUNCTION(getimagesizefromstring)
 */
final class getimagesizefromstring extends Internal
{
    public function __construct()
    {
        parent::__construct('getimagesizefromstring');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('getimagesizefromstring() expects one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'getimagesizefromstring', 0, 'data');
        $imageinfo = null;
        if (2 === $argc) {
            $imageinfo = [];
        }
        $result = VmImage::getImageSizeFromBytes($data, $imageinfo);
        if (false === $result) {
            VmImage::emitImageReadNotice($frame, 'getimagesizefromstring', $data);
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
        return JitGetimagesize::fromBytes($context, ...$args);
    }
}
