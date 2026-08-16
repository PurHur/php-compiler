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
 * flock() — VM via VmFs; JIT/AOT via __compiler_flock (issue #3141, php-src ext/standard/file.c).
 *
 * Excess argc → Zend ArgumentCountError (#30583).
 * Soft-null $operation → E_DEPRECATED + ValueError LOCK_* list (#31462).
 */
final class flock extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: 2..3 — #30583.
        $this->requireArgCountRange($frame, 'flock', 2, 3);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'flock');
        if (null === $frame->returnVar) {
            return;
        }
        $operation = VmFlockOperation::parseOperationForFrame($frame, 1);
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

        // Compile-time null: DEP+ValueError (soft) or TypeError (strict) without __compiler_flock (#31462).
        if (JitFlock::isCompileTimeNullOperation($args[1])) {
            return JitFlock::emitCompileTimeNullOperation($context, $args[1]);
        }

        return JitFlock::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'flock() handle'),
                $context->getTypeFromString('int64')
            ),
            $context->builder->truncOrBitCast(
                JitFlock::lowerOperation($context, $args[1]),
                $context->getTypeFromString('int64')
            )
        );
    }
}
