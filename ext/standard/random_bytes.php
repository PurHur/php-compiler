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
 * random_bytes() — CSPRNG via OS (VM: /dev/urandom; JIT/AOT: libc getrandom).
 */
final class random_bytes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('random_bytes() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $v->type) {
            throw new \LogicException('random_bytes() only supports integers in this compiler build');
        }
        $frame->returnVar->string(VmString::randomBytes($v->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('random_bytes() requires exactly one argument');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[0]->type) {
            throw new \LogicException('random_bytes() only supports integers in this compiler build');
        }

        $this->jitString($context, $args[0], 'randombytes() argument #1');
        return JitRandomBytes::generate($context, $context->helper->loadValue($args[0]));
    }
}
