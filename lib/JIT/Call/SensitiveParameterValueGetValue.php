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
 * SensitiveParameterValue::getValue() — JIT/AOT (#5127, #30867).
 *
 * php-src: Zend/zend_attributes.stub.php — getValue(): mixed
 */
final class SensitiveParameterValueGetValue implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SensitiveParameterValue::getValue() requires an object receiver');
        }
        // php-src ZEND_PARSE_PARAMETERS(0); $args[0] is $this (#30867)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'SensitiveParameterValue::getValue',
                    0,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'spv_getvalue_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $prop = $context->type->object->propertyFetch(
            $obj,
            SensitiveParamSupport::CLASS_NAME,
            SensitiveParamSupport::PROP_VALUE
        );
        $dest = JitValueBox::alloc($context);
        if (null !== ($prop->objectPropertySlot ?? null)) {
            $context->builder->call(
                $context->lookupFunction('__object__load_value_slot'),
                $prop->objectPropertySlot,
                $dest
            );
        } else {
            JitValueBox::copyFromPointer(
                $context,
                $dest,
                JitValueBox::valuePtrFromVariable($context, $prop)
            );
        }

        return $dest;
    }
}
