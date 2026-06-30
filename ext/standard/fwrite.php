<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fwrite() / fputs() — VM via VmFs; JIT/AOT via __compiler_fwrite (issue #1070, #6162). */
final class fwrite extends Internal
{
    public function __construct(string $name = 'fwrite')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException($fn.'() requires two or three arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $fn, 1, 'data');
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
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException($fn.'() requires two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() handle'),
            $i64
        );
        $dataStr = JitStringBuiltinArg::lower($context, $args[1], $fn, 1, 'data');
        if (3 === $argc) {
            $length = JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $args[2], $fn, 3, 'length');
        } else {
            $length = JitFwrite::lengthWriteAll($context, $dataStr);
        }

        return JitFwrite::invoke($context, $handle, $dataStr, $length);
    }
}
