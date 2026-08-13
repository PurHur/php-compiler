<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fwrite() / fputs() — VM via VmFs; JIT/AOT via __compiler_fwrite (issue #1070, #6162).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30721; php-src ext/standard/file.c).
 * Alias name (fputs) is preserved in the ACE message via getName().
 */
final class fwrite extends Internal
{
    public function __construct(string $name = 'fwrite')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        // php-src stub arity: 2..3 (#30721; ext/standard/file.c / file.stub.php).
        $this->requireArgCountRange($frame, $fn, 2, 3);
        $argc = \count($frame->calledArgs);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::stringBuiltinArgForFrame($frame, 1, $fn, 1, 'data');
        $length = null;
        if (3 === $argc) {
            $length = VmMath::parseNullableIntBuiltinArgForFrame($frame, 2, $fn, 3, 'length');
        }
        $written = VmFs::fwrite($handle, $data, $length);
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        // Catchable ArgumentCountError under AOT try/catch (#30721).
        if (!$this->requireArgCountRangeJit($context, $args, $fn, 2, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() handle'),
            $i64
        );
        $dataStr = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], $fn, 1, 'data');
        if (3 === $argc) {
            $length = JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $args[2], $fn, 3, 'length');
        } else {
            $length = JitFwrite::lengthWriteAll($context, $dataStr);
        }

        return JitFwrite::invoke($context, $handle, $dataStr, $length);
    }
}
