<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * flock() — VM via VmFs; JIT/AOT via __compiler_flock (issue #3141, php-src ext/standard/flock.c).
 *
 * Excess argc → Zend ArgumentCountError (#30583; php-src ext/standard/file.c).
 */
final class flock extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: 2..3 — #30583.
        $this->requireArgCountRange($frame, 'flock', 2, 3);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $operationVar = $frame->calledArgs[1]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'flock');
        if (null === $frame->returnVar) {
            return;
        }
        $operation = VmFlockOperation::parseOperation($operationVar);
        $wouldBlockOut = null;
        $hasWouldBlock = \count($frame->calledArgs) >= 3;
        if ($hasWouldBlock) {
            $ok = VmFs::flock($handle, $operation, $wouldBlockOut);
            // php-src php_flock: ZVAL_LONG(wouldblock, …) — integer 0/1 (#23352)
            $frame->calledArgs[2]->resolveIndirect()->int((int) $wouldBlockOut);
        } else {
            $ok = VmFs::flock($handle, $operation);
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30583).
        if (!$this->requireArgCountRangeJit($context, $args, 'flock', 2, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        JitResourceArg::rejectEnumCaseOperand($context, $args[0], 'flock', 0, 'stream');
        if (JitFlock::isCompileTimeNullOperation($args[1])) {
            JitFlock::emitCompileTimeNullOperationError($context);
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $args[1]->type) {
            JitFlock::guardValueBoxNullOperation($context, $args[1]);
        }

        return JitFlock::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'flock() handle'),
                $context->getTypeFromString('int64')
            ),
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'flock() operation'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
