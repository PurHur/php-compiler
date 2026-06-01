<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * forward_static_call() — invoke static method in caller late-static scope (#3197).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(forward_static_call)
 */
final class forward_static_call extends Internal
{
    public function __construct()
    {
        parent::__construct('forward_static_call');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('forward_static_call() requires at least one argument');
        }
        $callable = $frame->calledArgs[0];
        $extra = [];
        for ($i = 1; $i < $argc; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $extra[] = $copy;
        }
        $result = VmForwardStaticCall::invoke($frame, 'forward_static_call', $callable, ...$extra);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'forward_static_call() is not supported in JIT in this compiler build; use bin/vm.php'
        );
    }
}
