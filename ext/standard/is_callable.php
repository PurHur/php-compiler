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
 * is_callable() — whether a value is a valid callback (issue #3132).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(is_callable)
 */
final class is_callable extends Internal
{
    public function __construct()
    {
        parent::__construct('is_callable');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('is_callable() expects 1 to 3 arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $syntaxOnly = false;
        if ($argc >= 2) {
            $syntaxOnly = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $nameOut = ($argc >= 3) ? $frame->calledArgs[2] : null;
        $frame->returnVar->bool(
            VmCallable::isCallable($ctx, $frame->calledArgs[0], $syntaxOnly, $nameOut, $frame)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitIsCallable::invoke($context, ...$args);
    }
}
