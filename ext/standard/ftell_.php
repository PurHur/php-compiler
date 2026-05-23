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

/** ftell() — VM via VmFs; JIT/AOT via __compiler_ftell (issue #1190). */
final class ftell_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ftell');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('ftell() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('ftell() handle must be an integer in this compiler build');
        }
        $pos = VmFs::ftell($handleVar->toInt());
        if (false === $pos) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($pos);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('ftell() requires exactly one argument in this compiler build');
        }

        return JitFtell::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'ftell() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
