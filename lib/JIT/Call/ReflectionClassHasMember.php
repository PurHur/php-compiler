<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassHasMemberRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::hasMethod / hasProperty / hasConstant — JIT/AOT (#34072).
 *
 * Thin AOT previously had no proxies; ExternalMethod returned NULL.
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_has*
 */
final class ReflectionClassHasMember implements Call
{
    /**
     * @param 'hasMethod'|'hasProperty'|'hasConstant' $method
     */
    public function __construct(private readonly string $method)
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $display = 'ReflectionClass::'.$this->method;
        $userArgCount = \count($args) - 1;
        // hasMethod: exactly 1. hasProperty/hasConstant: 1 required, optional filter ignored (filter=0).
        $min = 1;
        $max = 'hasMethod' === $this->method ? 1 : 2;
        if ($userArgCount < $min || $userArgCount > $max) {
            if ($min === $max) {
                ExceptionBridge::emitArgumentCountErrorAndAbort(
                    $context,
                    VmClassMethod::exactUserArgCountMessage($display, $min, $userArgCount)
                );
            } else {
                ExceptionBridge::emitArgumentCountErrorAndAbort(
                    $context,
                    $display.'() expects at least '.$min.' argument'
                        .($userArgCount === 0 ? '' : 's').', '.$userArgCount.' given'
                );
            }
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classCstr, $classLen] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        $memberVar = JitNativeString::coerce($context, $args[1]);
        $memberStr = $context->helper->loadValue($memberVar);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $raw = $context->builder->pointerCast($memberStr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $memberData = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $memberLen = $context->builder->zExt($len, $sizeT);

        $flag = ReflectionClassHasMemberRuntime::invoke(
            $context,
            $this->method,
            $classCstr,
            $classLen,
            $context->builder->pointerCast($memberData, $i8p),
            $memberLen
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $flag);

        return $resultSlot;
    }
}
