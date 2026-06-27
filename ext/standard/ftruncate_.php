<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** ftruncate() — VM via VmFs; JIT/AOT via __compiler_ftruncate (issue #3256). */
final class ftruncate_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ftruncate');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('ftruncate() requires exactly two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'ftruncate');
        if (null === $frame->returnVar) {
            return;
        }
        $size = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ftruncate', 2, 'size');
        if ($size < 0) {
            throw new \ValueError('ftruncate(): Argument #2 ($size) must be greater than or equal to 0');
        }
        $frame->returnVar->bool(VmFs::ftruncate($handle, $size));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('ftruncate() requires exactly two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'ftruncate() handle'),
            $i64
        );
        $size = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'ftruncate() size'),
            $i64
        );

        return JitFtruncate::invoke($context, $handle, $size);
    }
}
