<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\PathSupport;
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
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'getimagesize() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'getimagesize() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmStreamPath::coerceNonEmptyPathArgForFrame(
            $frame,
            0,
            'getimagesize',
            'filename',
            PathSupport::EMPTY_PATH_CANNOT_BE_EMPTY_MESSAGE
        );
        $imageinfo = null;
        if (2 === $argc) {
            $imageinfo = [];
        }
        $result = VmImage::getImageSize($filename, $imageinfo);
        if (false === $result) {
            if (!VmImage::pathPayloadReadable($filename)) {
                VmStreamOpenFailure::warnFailedToOpen($frame, 'getimagesize', $filename);
            } elseif (VmImage::shouldEmitImageReadNoticeForPath($filename)) {
                VmImage::emitImageReadNotice($frame, 'getimagesize', $filename);
            }
            if (2 === $argc) {
                VmImage::writeImageInfoVariable($frame->calledArgs[1], []);
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
