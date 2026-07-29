<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Fiber::__construct(callable $callback) — JIT (#4019). */
final class FiberConstruct implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve (#24592). */
    public string $name = 'Fiber::__construct';

    /** @var list<string> php-src Zend/zend_fibers.stub.php */
    public array $paramNames = ['callback'];

    /** Implicit $this before PHP formals on construct call sites (#24592). */
    public int $namedArgsReceiverPrefix = 1;

    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('Fiber::__construct() expects a callable argument');
        }
        $fiberVar = $args[0];
        $callback = $args[1];
        $resumeName = FiberHelper::loadResumeNameFromObject($context, $callback);
        $fiberObj = $context->helper->loadValue($fiberVar);
        FiberHelper::storeResumeNameOnFiber($context, $fiberObj, $resumeName);
        $fiberVar->fiberResumeName = $resumeName;
        $context->fiberResumeByObjectValueId[spl_object_id($fiberObj)] = strtolower($resumeName);
        $zeroBits = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        FiberHelper::storeStateOnFiberObject($context, $fiberObj, $context->builder->inttoptr(
            $zeroBits->value,
            $context->getTypeFromString('__fiber_state__*')
        ));
        $context->type->object->markObjectConstructed($fiberObj);

        return JitValueBox::alloc($context);
    }
}
