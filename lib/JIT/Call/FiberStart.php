<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Fiber::start(mixed ...$args): mixed — JIT (#4019). */
final class FiberStart implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('Fiber::start() called without $this');
        }
        $fiberVar = $args[0];
        $resumeName = FiberHelper::resolveResumeLc($context, $fiberVar);
        $statePtr = FiberHelper::initFiberState($context);
        FiberHelper::storeStateOnFiberObject($context, $context->helper->loadValue($fiberVar), $statePtr);
        $fiberVar->fiberStatePtr = $statePtr;
        $fiberVar->fiberResumeName = $resumeName;
        $result = FiberHelper::runResumeAndBoxResult($context, $resumeName, $statePtr);

        return $result->value;
    }
}
