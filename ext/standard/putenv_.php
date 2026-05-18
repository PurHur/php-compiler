<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Value;

/** putenv() — set or unset process environment (VM; JIT defers to VM). */
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
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('putenv() requires a string assignment in this compiler build');
        }
        $assignment = $v->toString();
        $ok = \putenv($assignment);
        $frame->returnVar->bool($ok);
        if ($ok) {
            Superglobals::syncEnvAfterPutenv($assignment);
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

        return JitEnv::putenv($context, $context->helper->loadValue($args[0]));
    }
}
