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

/** feof() — VM via VmFs; JIT/AOT via __compiler_feof (issue #1188). */
final class feof_ extends Internal
{
    public function __construct()
    {
        parent::__construct('feof');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('feof() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('feof() handle must be an integer in this compiler build');
        }
        $frame->returnVar->bool(VmFs::feof($handleVar->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('feof() requires exactly one argument in this compiler build');
        }

        return JitFeof::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'feof() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
