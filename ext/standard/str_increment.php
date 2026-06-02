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
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \LogicException('str_increment() only supports strings in this compiler build');
        }
        $result = VmString::strIncrement($arg->toString());
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

        return JitStrIncdec::increment(
            $context,
            $this->jitString($context, $args[0], 'str_increment() argument #1')
        );
    }
}
