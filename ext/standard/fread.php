<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fread() — VM via VmFs; JIT/AOT via __compiler_fread (issue #1117, #9286). */
final class fread extends Internal
{
    private const LENGTH_ERROR = 'fread(): Argument #2 ($length) must be greater than 0';

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'fread', 2);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fread');
        if (null === $frame->returnVar) {
            return;
        }
        $length = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'fread', 2, 'length');
        if ($length <= 0) {
            throw new \ValueError(self::LENGTH_ERROR);
        }
        $data = VmFs::fread($handle, $length);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'fread', 2)) {
            return JitValueBox::alloc($context);
        }

        $i64 = $context->getTypeFromString('int64');

        $workBlock = BasicBlockHelper::append($context, 'fread_call_work');
        $context->builder->branch($workBlock);
        $context->builder->positionAtEnd($workBlock);

        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fread() handle'),
            $i64
        );
        $length = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'fread', 2, 'length');
        JitFread::emitRuntimeLengthGuard($context, $length);

        return JitFread::invoke($context, $handle, $length);
    }
}
