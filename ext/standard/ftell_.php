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
 * ftell() — VM via VmFs; JIT/AOT via __compiler_ftell (issue #1190).
 *
 * Excess argc → Zend ArgumentCountError (#30584; php-src ext/standard/file.c).
 */
final class ftell_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ftell');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 — #30584.
        $this->requireExactArgCount($frame, 'ftell', 1);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'ftell');
        if (null === $frame->returnVar) {
            return;
        }
        $pos = VmFs::ftell($handle);
        if (false === $pos) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($pos);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireExactJitArgCount($context, $args, 'ftell', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $i64 = $context->getTypeFromString('int64');

        return JitFtell::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'ftell() handle'),
                $i64
            )
        );
    }
}
