<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_check_encoding() — multibyte validity probe (php-src ext/mbstring/mbstring.c; #4571).
 *
 * VM only — delegates to host mbstring when available; UTF-8/ASCII fallback otherwise.
 */
final class mb_check_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_check_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_check_encoding() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $encoding = null;
        if (2 === $argc) {
            $encoding = VmMbstring::coerceEncodingString(
                $frame->calledArgs[1],
                'mb_check_encoding',
                1
            );
        }

        if (0 === $argc) {
            $frame->returnVar->bool(VmMbstring::checkEncoding());

            return;
        }

        $frame->returnVar->bool(
            VmMbstring::checkEncodingForVariable($frame->calledArgs[0], $encoding)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_check_encoding() is not implemented for JIT/AOT in this compiler build');
    }
}
