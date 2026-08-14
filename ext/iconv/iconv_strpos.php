<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;

/**
 * iconv_strpos() — find substring in encoding (php-src ext/iconv/iconv.c; #6247).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30891).
 */
final class iconv_strpos extends IconvStringFunction
{
    public function __construct()
    {
        parent::__construct('iconv_strpos');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 2..4 — excess uses at-most wording (#30891).
        $this->requireArgCountRange($frame, 'iconv_strpos', 2, 4);
        $argc = \count($frame->calledArgs);
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
