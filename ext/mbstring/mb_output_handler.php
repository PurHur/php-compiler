<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_output_handler() — OB callback converting to mb_http_output encoding
 * (php-src ext/mbstring/mbstring.c; #20014).
 */
final class mb_output_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_output_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'mb_output_handler() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmMbstring::coerceOutputHandlerStringArg(
            $frame->calledArgs[0],
            'mb_output_handler',
            0
        );
        $status = VmMbstring::coerceOutputHandlerStatusArg(
            $frame->calledArgs[1],
            'mb_output_handler',
            1
        );
        $frame->returnVar->string(VmMbstring::outputHandler($string, $status));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_output_handler() JIT is not supported in this compiler build'
        );
    }
}
