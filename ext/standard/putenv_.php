<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** putenv() — set/unset process environment (VM; JIT/AOT via libc putenv). */
final class putenv_ extends Internal
{
    public function __construct()
    {
        parent::__construct('putenv');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('putenv() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('putenv() requires a string assignment in this compiler build');
        }
        $ok = VmEnv::putenv($v->toString());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('putenv() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('putenv() requires a string assignment in this compiler build');
        }

        return JitEnv::putenv($context, $this->jitString($context, $args[0], 'putenv() assignment'));
    }
}
