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
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionMethod / ReflectionProperty::getDeclaringClass() — JIT/AOT (#34020).
 *
 * Thin AOT previously had no proxy; ExternalMethod returned an allocated ReflectionClass
 * without seeding `$name`, so `->getName()` / `$name` SIGSEGV'd (ext/reflection/php_reflection.c).
 *
 * Seed `$name` from the receiver's declaring-class string slot (`$class`), matching
 * {@see ReflectionClassGetProperty} / construct-time Zend public surface.
 */
final class ReflectionGetDeclaringClass implements Call
{
    public function __construct(
        private string $receiverClass,
        private string $declaringClassProp,
        private string $apiLabel,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    $this->apiLabel,
                    0,
                    $userArgCount
                )
            );
            $slug = strtolower(str_replace(['::', '\\'], '_', $this->apiLabel));
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_'.$slug.'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classSafe, $classLen] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            $this->receiverClass,
            $this->declaringClassProp
        );

        $rcClassId = $context->type->object->lookup('ReflectionClass');
        $rcObj = $context->type->object->allocate($rcClassId);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rcObj,
            'ReflectionClass',
            ReflectionSupport::PROP_CLASS_NAME,
            $classSafe,
            $classLen
        );
        ReflectionSetup::markConstructed($context, $rcObj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $rcObj
        );

        return JitValueBox::pointer($context, $slot);
    }
}
