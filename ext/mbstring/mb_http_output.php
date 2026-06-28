<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_http_output() — HTTP output encoding (php-src ext/mbstring/mbstring.c; #13100). */
final class mb_http_output extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_http_output');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_http_output() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->string(MbstringState::httpOutput());

            return;
        }
        $encoding = VmMbstring::coerceMbEncodingNameArg(
            $frame->calledArgs[0],
            'mb_http_output',
            0
        );
        $frame->returnVar->bool(MbstringState::httpOutput($encoding));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_http_output() JIT is not supported in this compiler build'
        );
    }
}
