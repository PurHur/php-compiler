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
        $meta = null;
        if (isset($registry[$lcClass][$propertyName])) {
            $meta = $registry[$lcClass][$propertyName];
        } elseif (isset($registry[$lcClass][$propLc])) {
            $meta = $registry[$lcClass][$propLc];
        }
        if (!is_array($meta) || (!isset($meta['get']) && !isset($meta['set']))) {
            return null;
        }
        if (isset($meta['setBacking'])) {
            return $meta['setBacking'];
        }
        if (isset($meta['getBacking'])) {
            return $meta['getBacking'];
        }

        return $propertyName;
    }

    /**
     * Same-name backed (non-virtual) get hook — zend_should_call_hook is false while the
     * slot is UNDEF, so isset/empty/?? must not invoke get (#30739, #11617, zend_property_hooks.c).
     *
     * Virtual and distinct-backing hooks still invoke get (#29214, #23339).
     *
     * @param array<string, array<string, mixed>> $registry propertyHookRegistry
     */
    public static function sameNameBackedGetHook(
        array $registry,
        string $declaringClass,
        string $propertyName
    ): bool {
        $lcClass = strtolower(ltrim($declaringClass, '\\'));
        $propLc = strtolower($propertyName);
        $meta = $registry[$lcClass][$propertyName]
            ?? $registry[$lcClass][$propLc]
            ?? null;
        if (!is_array($meta) || !isset($meta['get'])) {
            return false;
        }
        if (!empty($meta['virtual'])) {
            return false;
        }
        $backing = $meta['setBacking'] ?? $meta['getBacking'] ?? $propertyName;

        return is_string($backing) && '' !== $backing && 0 === strcasecmp($backing, $propertyName);
    }

    /**
     * Dim/append/unset-dim on hooked props requires `&get` (zend_object_handlers, #28590 / #29748).
     *
     * @param array<string, array<string, mixed>> $registry propertyHookRegistry
     */
    public static function dimWriteRequiresByRefGet(
        array $registry,
        string $declaringClass,
        string $propertyName
    ): bool {
        $lcClass = strtolower(ltrim($declaringClass, '\\'));
        $propLc = strtolower($propertyName);
        $meta = $registry[$lcClass][$propertyName]
            ?? $registry[$lcClass][$propLc]
            ?? null;
        if (!is_array($meta)) {
            return false;
        }
        if (!isset($meta['get']) && !isset($meta['set'])) {
            return false;
        }
        // `&get` may mutate the live target; non-by-ref get yields a temporary (#28590).
        return empty($meta['getByRef']);
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
        if (null !== $context->jitCurrentBlock) {
            $block = $context->jitCurrentBlock;
        }
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
            $classVal = null;
            if (isset($block->func->class->value)) {
                $classVal = $block->func->class->value;
            }
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
