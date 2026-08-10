<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;

/**
 * iconv_strlen() — character count in encoding (php-src ext/iconv/iconv.c; #6247).
 */
final class iconv_strlen extends IconvStringFunction
{
    public function __construct()
    {
        parent::__construct('iconv_strlen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'iconv_strlen() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
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
