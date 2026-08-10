<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;

/**
 * iconv_strpos() — find substring in encoding (php-src ext/iconv/iconv.c; #6247).
 */
final class iconv_strpos extends IconvStringFunction
{
    public function __construct()
    {
        parent::__construct('iconv_strpos');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'iconv_strpos() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        $haystack = $this->coerceInputString($frame, 0, 'haystack');
        $needle = $this->coerceInputString($frame, 1, 'needle');
        if (null === $frame->returnVar) {
            return;
        }
        $offset = 3 <= $argc ? $this->coerceOffset($frame, 2) : 0;
        $encoding = 4 === $argc
            ? $this->coerceEncoding($frame, 3)
            : IconvEncodingState::getInternalEncoding();
        $this->writeIntOrFalse($frame, VmIconv::iconvStrpos($haystack, $needle, $offset, $encoding, $frame));
    }
}
