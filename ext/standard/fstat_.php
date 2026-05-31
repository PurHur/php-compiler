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

/** fstat() — stat array for open stream resources (issue #3482, php-src ext/standard/streams.c). */
final class fstat_ extends Internal
{
    public function __construct()
    {
        parent::__construct('fstat');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fstat() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fstat() handle must be an integer in this compiler build');
        }
        $info = VmFs::fstat($handleVar->toInt());
        if (false === $info) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($info);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fstat() requires exactly one argument in this compiler build');
        }

        $path = $context->builder->call(
            $context->lookupFunction('__phpc_stream_path'),
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fstat() handle'),
                $context->getTypeFromString('int64')
            )
        );

        return JitStatArray::invoke($context, $path, false);
    }
}
