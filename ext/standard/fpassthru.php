<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fpassthru() — VM via VmFs; JIT/AOT via __compiler_fpassthru (issue #1194). */
final class fpassthru extends Internal
{
    public function __construct()
    {
        parent::__construct('fpassthru');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fpassthru() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fpassthru() handle must be an integer in this compiler build');
        }
        $result = VmFs::fpassthru($handleVar->toInt());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fpassthru() requires exactly one argument in this compiler build');
        }

        return JitFpassthru::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fpassthru() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
