<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fseek() — VM via VmFs; JIT/AOT via __compiler_fseek (issue #1191).
 *
 * Excess argc → Zend ArgumentCountError (#30584; php-src ext/standard/file.c).
 */
final class fseek extends Internal
{
    public function __construct()
    {
        parent::__construct('fseek');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 2..3 — #30584.
        $this->requireArgCountRange($frame, 'fseek', 2, 3);
        $argc = \count($frame->calledArgs);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fseek');
        if (null === $frame->returnVar) {
            return;
        }
        $offsetInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'fseek', 2, 'offset');
        $whence = \SEEK_SET;
        if (3 === $argc) {
            $whence = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'fseek', 3, 'whence');
        }
        $frame->returnVar->int(VmFs::fseek($handle, $offsetInt, $whence));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireArgCountRangeJit($context, $args, 'fseek', 2, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fseek() handle'),
            $i64
        );
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'fseek', 2, 'offset');
        if (3 === $argc) {
            $whence = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'fseek() whence'),
                $i64
            );
        } else {
            $whence = $i64->constInt(\SEEK_SET, false);
        }

        return JitFseek::invoke($context, $handle, $offset, $whence);
    }
}
