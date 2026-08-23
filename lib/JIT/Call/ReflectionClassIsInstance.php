<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::isInstance() — JIT/AOT (#34098, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod returned NULL. Dispatch the
 * reflected class_id to {@see \PHPCompiler\JIT\Builtin\Type\Object_::emitInstanceOf}
 * (same instanceof tables as the language operator).
 *
 * php-src: zim_ReflectionClass_isInstance
 */
final class ReflectionClassIsInstance implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_isInstance — exactly 1 user arg; $args[0] is $this
        $userArgCount = \count($args) - 1;
        if (1 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::isInstance',
                    1,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_isinstance_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $reflectedClassId = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );
        $subject = $args[1];
        $object = $context->type->object;
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);

        foreach ($object->allClassNamesById() as $id => $name) {
            $isReflected = $context->builder->icmp(
                Builder::INT_EQ,
                $reflectedClassId,
                $context->constantFromInteger((int) $id, 'int64')
            );
            $check = $object->emitInstanceOf($subject, (string) $name);
            $flag = Variable::TYPE_NATIVE_BOOL === $check->type
                ? $check->value
                : $context->helper->loadValue($check);
            $acc = $context->builder->select($isReflected, $flag, $acc);
        }

        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $acc);

        return $resultSlot;
    }
}
