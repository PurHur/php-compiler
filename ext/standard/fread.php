<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fread() — VM via VmFs; JIT/AOT via __compiler_fread (issue #1117). */
final class fread extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'fread', 2);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $lenVar = $frame->calledArgs[1]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fread');
        if (null === $frame->returnVar) {
            return;
        }
        $length = VmMath::parseIntBuiltinArg($lenVar, 'fread', 2, 'length');
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

        return JitFread::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fread() handle'),
                $i64
            ),
            JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'fread', 2, 'length')
        );
    }
}
