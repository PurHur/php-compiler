<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** getrusage() — process resource usage (JIT/AOT via __compiler_getrusage, issue #3240). */
final class getrusage extends Internal
{
    public function __construct()
    {
        parent::__construct('getrusage');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('getrusage() accepts at most one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $who = 0;
        if (1 === $argc) {
            $whoVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $whoVar->type) {
                throw new \LogicException('getrusage() who must be an integer in this compiler build');
            }
            $who = $whoVar->toInt();
        }
        $usage = VmProcess::getrusage($who);
        if (false === $usage) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($usage);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('getrusage() accepts at most one argument in this compiler build');
        }

        return JitGetrusage::invoke($context, $args[0] ?? null);
    }
}
