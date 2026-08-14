<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;

/**
 * iconv_strlen() — character count in encoding (php-src ext/iconv/iconv.c; #6247).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30891).
 */
final class iconv_strlen extends IconvStringFunction
{
    public function __construct()
    {
        parent::__construct('iconv_strlen');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 1..2 — excess uses at-most wording (#30891).
        $this->requireArgCountRange($frame, 'iconv_strlen', 1, 2);
        $argc = \count($frame->calledArgs);
        $input = $this->coerceInputString($frame, 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 2 === $argc
            ? $this->coerceEncoding($frame, 1)
            : IconvEncodingState::getInternalEncoding();
        $this->writeIntOrFalse($frame, VmIconv::iconvStrlen($input, $encoding, $frame));
    }
}
