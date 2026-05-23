<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** unlink() — VM via VmFs; JIT/AOT via libc unlink(2). */
final class unlink_ extends Internal
{
    public function __construct()
    {
        parent::__construct('unlink');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('unlink() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('unlink() requires a string path in this compiler build');
        }
        $frame->returnVar->bool(@\unlink($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('unlink() requires exactly one argument in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('unlink() requires a string path in this compiler build');
        }

        return JitUnlink::invoke($context, $context->helper->loadValue($args[0]));
    }
}
