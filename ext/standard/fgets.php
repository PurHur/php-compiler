<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fgets() — VM via VmFs; JIT/AOT via __compiler_fgets (issue #1187). */
final class fgets extends Internal
{
    private const LENGTH_ERROR = 'fgets(): Argument #2 ($length) must be greater than 0';

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
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fgets');
        if (null === $frame->returnVar) {
            return;
        }
        $length = null;
        if (2 === $argc) {
            $lenVar = $frame->calledArgs[1]->resolveIndirect();
            $length = VmMath::parseIntBuiltinArg($lenVar, 'fgets', 2, 'length');
            if ($length <= 0) {
                throw new \ValueError(self::LENGTH_ERROR);
            }
        }
        $line = VmFs::fgets($handle, $length);
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
            $length = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'fgets', 2, 'length');
            JitFgets::emitRuntimeLengthGuard($context, $length);
        } else {
            $length = $i64->constInt(-1, true);
        }

        return JitFgets::invoke($context, $handle, $length);
    }
}
