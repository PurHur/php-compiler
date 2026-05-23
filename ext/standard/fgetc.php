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

/** fgetc() — VM via VmFs; JIT/AOT via __compiler_fgetc (issue #1195). */
final class fgetc extends Internal
{
    public function __construct()
    {
        parent::__construct('fgetc');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fgetc() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fgetc() handle must be an integer in this compiler build');
        }
        $data = VmFs::fgetc($handleVar->toInt());
        if (false === $data) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($data);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fgetc() requires exactly one argument in this compiler build');
        }

        return JitFgetc::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fgetc() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
