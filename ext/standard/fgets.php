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

/** fgets() — VM via VmFs; JIT/AOT via __compiler_fgets (issue #1187). */
final class fgets extends Internal
{
    public function __construct()
    {
        parent::__construct('fgets');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('fgets() requires one or two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fgets() handle must be an integer in this compiler build');
        }
        $length = null;
        if (2 === $argc) {
            $lenVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenVar->type) {
                throw new \LogicException('fgets() length must be an integer in this compiler build');
            }
            $length = $lenVar->toInt();
        }
        $line = VmFs::fgets($handleVar->toInt(), $length);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('fgets() requires one or two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fgets() handle'),
            $i64
        );
        if (2 === $argc) {
            $length = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'fgets() length'),
                $i64
            );
        } else {
            $length = $i64->constInt(-1, true);
        }

        return JitFgets::invoke($context, $handle, $length);
    }
}
