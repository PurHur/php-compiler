<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;

/**
 * iconv_strrpos() — reverse find in encoding (php-src ext/iconv/iconv.c; #6247).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30891).
 */
final class iconv_strrpos extends IconvStringFunction
{
    public function __construct()
    {
        parent::__construct('iconv_strrpos');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 2..3 — excess uses at-most wording (#30891).
        $this->requireArgCountRange($frame, 'iconv_strrpos', 2, 3);
        $argc = \count($frame->calledArgs);
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
