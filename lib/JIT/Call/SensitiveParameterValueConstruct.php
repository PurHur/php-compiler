<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPLLVM\Value;

/**
 * SensitiveParameterValue::__construct(mixed $value) — JIT/AOT (#5127, #30867).
 *
 * php-src: Zend/zend_attributes.stub.php — __construct(mixed $value)
 */
final class SensitiveParameterValueConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SensitiveParameterValue::__construct() requires $this');
        }
        // User arity excludes $this (#30867).
        $userArgCount = \count($args) - 1;
        if (1 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'SensitiveParameterValue::__construct',
                    1,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'spv_construct_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $context->type->object->storeInstanceProperty(
            $obj,
            SensitiveParamSupport::CLASS_NAME,
            SensitiveParamSupport::PROP_VALUE,
            $args[1]
        );
        $context->type->object->markObjectConstructed($obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
