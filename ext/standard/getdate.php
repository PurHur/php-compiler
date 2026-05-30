<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** getdate() — associative date/time breakdown (JIT/AOT via __compiler_getdate, issue #3510). */
final class getdate extends Internal
{
    public function __construct()
    {
        parent::__construct('getdate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('getdate() accepts at most one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $timestamp = null;
        if (1 === $argc) {
            $tsVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $tsVar->type) {
                $timestamp = $tsVar->toInt();
            } elseif (Variable::TYPE_NULL !== $tsVar->type) {
                throw new \LogicException('getdate() timestamp must be an integer or null in this compiler build');
            }
        }
        $frame->returnVar->array(VmDate::getdate($timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('getdate() accepts at most one argument in this compiler build');
        }

        return JitGetdate::invoke($context, $args[0] ?? null);
    }
}
