<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * forward_static_call_array() — argv array variant (#3197).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(forward_static_call_array)
 */
final class forward_static_call_array extends Internal
{
    public function __construct()
    {
        parent::__construct('forward_static_call_array');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('forward_static_call_array() requires exactly two arguments');
        }
        $callable = $frame->calledArgs[0];
        $params = VmForwardStaticCall::unpackParamsArray(
            $frame->calledArgs[1],
            'forward_static_call_array'
        );
        $result = VmForwardStaticCall::invoke(
            $frame,
            'forward_static_call_array',
            $callable,
            ...$params
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'forward_static_call_array() is not supported in JIT in this compiler build; use bin/vm.php'
        );
    }
}
