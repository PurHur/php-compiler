<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\StringInfo;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionExtension::getVersion() — JIT/AOT (#34016, ext/reflection/php_reflection.c).
 *
 * Mirrors VM {@see \PHPCompiler\VM\Builtin\ReflectionExtensionGetVersion} /
 * {@see \PHPCompiler\ext\standard\VmReflection::reflectionExtensionVersion}:
 * phpversion($name) with fallback to {@see CompilerVersion::reportedPhpVersion()}.
 */
final class ReflectionExtensionGetVersion implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionExtension_getVersion — 0 user args
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionExtension::getVersion',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'refl_ext_getversion_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $propVar = $context->type->object->propertyFetch(
            $obj,
            'ReflectionExtension',
            ReflectionSupport::PROP_EXTENSION_NAME
        );
        if (Variable::TYPE_VALUE === $propVar->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $propVar);
        } else {
            $valuePtr = $context->builder->pointerCast(
                $context->helper->loadValue($propVar),
                $context->getTypeFromString('__value__*')
            );
        }
        $nameStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );

        StringInfo::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_phpversion'),
            $nameStr
        );
        $fallbackText = CompilerVersion::reportedPhpVersion();
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $fallback = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($fallbackText), false),
            $context->builder->pointerCast($context->constantFromString($fallbackText), $charPtr)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());

        return $context->builder->select($isNull, $fallback, $raw);
    }
}
