<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\SourcePreprocessor\PropertyHooks;

/**
 * Shared property-hook compile-time guards for VM + JIT lowering (#10112, php-in-PHP).
 *
 * php-src: Zend/zend_property_hooks.c
 */
final class PropertyHookJitHelper
{
    /**
     * @param array<string, array<string, mixed>> $registry propertyHookRegistry slice
     */
    public static function hookedPropertyBackingName(
        array $registry,
        string $declaringClass,
        string $propertyName
    ): ?string {
        $lcClass = strtolower(ltrim($declaringClass, '\\'));
        $propLc = strtolower($propertyName);
        $meta = $registry[$lcClass][$propertyName]
            ?? $registry[$lcClass][$propLc]
            ?? null;
        if (!is_array($meta) || (!isset($meta['get']) && !isset($meta['set']))) {
            return null;
        }

        return $meta['setBacking'] ?? $meta['getBacking'] ?? $propertyName;
    }

    public static function isRawHookWrite(
        Context $context,
        string $propertyName,
        ?Block $block
    ): bool {
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
}
