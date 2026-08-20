<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** phpcredits() — runtime credits report (ext/standard/info.c parity, #3359, #5304). */
final class phpcredits extends Internal
{
    public function __construct()
    {
        parent::__construct('phpcredits');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('phpcredits() accepts at most one argument');
        }
        $flags = VmInfo::CREDITS_ALL;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                if (Variable::TYPE_INTEGER !== $arg->type && Variable::TYPE_FLOAT !== $arg->type) {
                    throw new \LogicException('phpcredits() flags must be an integer in this compiler build');
                }
                $flags = (int) $arg->toInt();
            }
        }
        VmInfo::phpcredits($flags);
        if (null !== $frame->returnVar) {
            // php-src ext/standard/info.c PHP_FUNCTION(phpcredits) — RETURN_TRUE (#24508)
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'phpcredits() accepts at most 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitInfo::phpcredits($context, $argc > 0 ? $args[0] : null);
    }
}
