<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * fflush() — VM via VmFs; JIT/AOT via __compiler_fflush (issue #1189).
 *
 * Excess argc → Zend ArgumentCountError (#30584; php-src ext/standard/file.c).
 */
final class fflush_ extends Internal
{
    public function __construct()
    {
        parent::__construct('fflush');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 — #30584.
        $this->requireExactArgCount($frame, 'fflush', 1);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fflush');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::fflush($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireExactJitArgCount($context, $args, 'fflush', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitFflush::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fflush() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
