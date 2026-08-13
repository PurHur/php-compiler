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
 * feof() — VM via VmFs; JIT/AOT via __compiler_feof (issue #1188).
 *
 * Excess argc → Zend ArgumentCountError (#30584; php-src ext/standard/file.c).
 */
final class feof_ extends Internal
{
    public function __construct()
    {
        parent::__construct('feof');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 — #30584.
        $this->requireExactArgCount($frame, 'feof', 1);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'feof');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::feof($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireExactJitArgCount($context, $args, 'feof', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
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
