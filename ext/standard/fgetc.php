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
 * fgetc() — VM via VmFs; JIT/AOT via __compiler_fgetc (issue #1195).
 *
 * Excess argc → Zend ArgumentCountError (#30584; php-src ext/standard/file.c).
 */
final class fgetc extends Internal
{
    public function __construct()
    {
        parent::__construct('fgetc');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 — #30584.
        $this->requireExactArgCount($frame, 'fgetc', 1);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fgetc');
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmFs::fgetc($handle);
        if (false === $data) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($data);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireExactJitArgCount($context, $args, 'fgetc', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitFgetc::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fgetc() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
