<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrIncdec;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * str_increment() — PHP 8.3 alphanumeric string increment (issue #3102).
 */
final class str_increment extends Internal
{
    public function __construct()
    {
        parent::__construct('str_increment');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_increment() requires exactly one argument in this compiler build');
        }
        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'str_increment', 0, 'string');
        $result = VmString::strIncrement($input);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($result);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('str_increment() requires exactly one argument in this compiler build');
        }

        $input = JitStringBuiltinArg::lower($context, $args[0], 'str_increment', 0, 'string');

        return StringStrIncdec::invokeIncrement($context, $input);
    }
}
