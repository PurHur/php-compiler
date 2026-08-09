<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * var_export() subset for bootstrap/AOT (issue #4474 repro, #1492).
 */
final class var_export extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/var.c — ArgumentCountError (#28474).
        $this->requireArgCountRange($frame, 'var_export', 1, 2);
        $v = $frame->calledArgs[0]->resolveIndirect();
        TypedPropertyCheck::assertReadable($v);
        $return = false;
        if (isset($frame->calledArgs[1])) {
            $return = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $vm = $frame->vmContext?->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('var_export() requires an active VM');
        }
        $exported = VmVarExport::formatVariable($vm, $v, 0, $frame);
        if ($return) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->string($exported);
        } else {
            OutputBuffer::append($exported);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'var_export', 1, 2)) {
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
        }

        return JitVarExport::invoke($context, ...$args);
    }
}
