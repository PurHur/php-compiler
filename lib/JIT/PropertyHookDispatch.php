<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPLLVM\Value;

/**
 * Dispatch property get/set hooks lowered to __phpc_property_* methods (#3145, #3723).
 *
 * php-src: Zend/zend_property_hooks.c (VM parity via preprocessor + hook methods).
 */
final class PropertyHookDispatch
{
    /**
     * Invoke set hook instead of direct property store when applicable.
     */
    public static function emitSetHookIfNeeded(
        Context $context,
        Variable $lvalue,
        Variable $value,
        ?Block $enclosingBlock
    ): bool {
        if (null === $lvalue->objectPropertySlot || null === $lvalue->objectPropertyName) {
            return false;
        }
        if (null === $lvalue->objectPropertyReceiver) {
            return false;
        }
        if (self::isRawHookWrite($context, $lvalue->objectPropertyName, $enclosingBlock)) {
            return false;
        }
        $className = $lvalue->objectPropertyClassName ?? 'stdclass';
        $hookLc = strtolower(PropertyHooks::setHookMethodName($lvalue->objectPropertyName));
        $proxyName = self::resolveHookProxy($context, $className, $hookLc);
        if (null === $proxyName) {
            return false;
        }

        $receiver = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $lvalue->objectPropertyReceiver
        );
        $toCall = $context->resolveFunctionProxy($proxyName);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $toCall->call($context, $receiver, $value);
        $context->callerStrictTypes = $prevStrict;

        return true;
    }

    /**
     * Invoke get hook instead of loading the backing slot when applicable.
     */
    public static function tryEmitPropertyGet(
        Context $context,
        Value $receiver,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        if (self::isRawHookWrite($context, $propertyName, $enclosingBlock)) {
            return null;
        }
        $hookLc = strtolower(PropertyHooks::getHookMethodName($propertyName));
        $proxyName = self::resolveHookProxy($context, $declaringClass, $hookLc);
        if (null === $proxyName) {
            return null;
        }

        $receiverVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $receiver
        );
        $toCall = $context->resolveFunctionProxy($proxyName);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $hookValue = $toCall->call($context, $receiverVar);
        $context->callerStrictTypes = $prevStrict;

        return $hookValue;
    }

    private static function isRawHookWrite(Context $context, string $propertyName, ?Block $block): bool
    {
        if (null !== $context->jitPropertyHookRawProperty
            && $context->jitPropertyHookRawProperty === $propertyName) {
            return true;
        }
        $block = $context->jitCurrentBlock ?? $block;
        if (null === $block || null === $block->func) {
            return false;
        }
        $funcName = strtolower($block->func->name);
        if (str_contains($funcName, '::')) {
            $funcName = substr($funcName, strrpos($funcName, '::') + 2);
        }
        $rawFromMethod = PropertyHooks::propertyNameFromSetHookMethod($funcName);
        if (null !== $rawFromMethod && $rawFromMethod === $propertyName) {
            return true;
        }
        $rawFromGet = PropertyHooks::propertyNameFromGetHookMethod($funcName);
        if (null !== $rawFromGet && $rawFromGet === $propertyName) {
            return true;
        }
        $wantSet = strtolower(PropertyHooks::setHookMethodName($propertyName));
        if ($funcName === $wantSet) {
            return true;
        }
        $wantGet = strtolower(PropertyHooks::getHookMethodName($propertyName));
        if ($funcName === $wantGet) {
            return true;
        }
        if (null !== $block->func->class) {
            $classVal = $block->func->class->value ?? null;
            if (is_string($classVal) && '' !== $classVal) {
                $qualifiedSet = strtolower($classVal.'::'.$wantSet);
                if ($funcName === $qualifiedSet || strtolower($block->func->name) === $qualifiedSet) {
                    return true;
                }
                $qualifiedGet = strtolower($classVal.'::'.$wantGet);

                return $funcName === $qualifiedGet || strtolower($block->func->name) === $qualifiedGet;
            }
        }

        return false;
    }

    private static function resolveHookProxy(Context $context, string $className, string $hookMethodLc): ?string
    {
        $objectType = $context->type->object;
        assert($objectType instanceof Object_);
        $visited = [];
        $current = strtolower(ltrim($className, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$hookMethodLc;
            if ($context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            $parent = $objectType->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }
}
