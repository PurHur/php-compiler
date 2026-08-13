<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fclose() — VM via VmFs; JIT/AOT via __compiler_fclose (issue #1117).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30721; php-src ext/standard/file.c).
 */
final class fclose extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30721; ext/standard/file.c / file.stub.php).
        $this->requireExactArgCount($frame, 'fclose', 1);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fclose');
        $closed = VmFs::fclose($handle);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($closed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30721).
        if (!$this->requireExactJitArgCount($context, $args, 'fclose', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitFclose::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fclose() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
