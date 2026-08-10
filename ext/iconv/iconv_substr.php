<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;

/**
 * iconv_substr() — substring in encoding (php-src ext/iconv/iconv.c; #6247).
 */
final class iconv_substr extends IconvStringFunction
{
    public function __construct()
    {
        parent::__construct('iconv_substr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'iconv_substr() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        $input = $this->coerceInputString($frame, 0, 'string');
        $offset = $this->coerceOffset($frame, 1);
        if (null === $frame->returnVar) {
            return;
        }
        $length = null;
        $encoding = IconvEncodingState::getInternalEncoding();
        if (3 === $argc) {
            $length = $this->coerceLength($frame, 2);
        } elseif (4 === $argc) {
            $length = $this->coerceLength($frame, 2);
            $encoding = $this->coerceEncoding($frame, 3);
        }
        $this->writeStringOrFalse($frame, VmIconv::iconvSubstr($input, $offset, $length, $encoding, $frame));
    }
}
