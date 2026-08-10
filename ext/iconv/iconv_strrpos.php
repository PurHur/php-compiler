<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;

/**
 * iconv_strrpos() — reverse find in encoding (php-src ext/iconv/iconv.c; #6247).
 */
final class iconv_strrpos extends IconvStringFunction
{
    public function __construct()
    {
        parent::__construct('iconv_strrpos');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'iconv_strrpos() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $haystack = $this->coerceInputString($frame, 0, 'haystack');
        $needle = $this->coerceInputString($frame, 1, 'needle');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 3 === $argc
            ? $this->coerceEncoding($frame, 2)
            : IconvEncodingState::getInternalEncoding();
        $this->writeIntOrFalse($frame, VmIconv::iconvStrrpos($haystack, $needle, $encoding, $frame));
    }
}
