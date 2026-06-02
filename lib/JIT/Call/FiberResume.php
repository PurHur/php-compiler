<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Fiber::resume(mixed $value = null): mixed — JIT (#4019). */
final class FiberResume implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('Fiber::resume() called without $this');
        }
        $fiberVar = $args[0];
        $resumeName = FiberHelper::resolveResumeLc($context, $fiberVar);
        $statePtr = $fiberVar->fiberStatePtr ?? FiberHelper::loadStateFromFiberObject($context, $fiberVar);
        $map = $context->structFieldMap['__fiber_state__'];
        $resumeArgField = $context->builder->structGep($statePtr, $map['resume_argument']);
        if (count($args) >= 2) {
            FiberHelper::assignValueField($context, $resumeArgField, $args[1], null);
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $resumeArgField)
            );
        }
        $result = FiberHelper::runResumeAndBoxResult($context, $resumeName, $statePtr);

        return $result->value;
    }
}
