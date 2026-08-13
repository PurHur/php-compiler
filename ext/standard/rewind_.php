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
 * rewind() — VM via VmFs; JIT/AOT via __compiler_rewind (issue #3579).
 *
 * Excess argc → Zend ArgumentCountError (#30584; php-src ext/standard/file.c).
 */
final class rewind_ extends Internal
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 — #30584.
        $this->requireExactArgCount($frame, 'rewind', 1);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'rewind');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::rewind($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireExactJitArgCount($context, $args, 'rewind', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitRewind::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'rewind() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
