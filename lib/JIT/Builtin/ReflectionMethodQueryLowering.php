<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ParamArgumentCountError;
use PHPCompiler\VM\ReflectionSupport;

/**
 * Record compile-time user class method metadata for thin AOT
 * ReflectionMethod::{isPublic,isStatic,getNumberOfParameters,...} (#34216).
 *
 * Peer of {@see ReflectionFunctionParamCountLowering} (#34218).
 */
final class ReflectionMethodQueryLowering
{
    /** @var array<string, array<string, array{total: int, required: int}>> */
    private static array $userMethods = [];

    public static function recordUserMethod(
        string $className,
        string $methodName,
        int $paramCount,
        int $requiredCount
    ): void {
        $classLc = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);
        if ('' === $classLc || '' === $methodLc || !self::isUserScriptClassLc($classLc)) {
            return;
        }
        self::$userMethods[$classLc][$methodLc] = [
            'total' => max(0, $paramCount),
            'required' => max(0, min($requiredCount, max(0, $paramCount))),
        ];
    }

    public static function recordUserMethodFromBlock(string $className, string $methodName, \PHPCompiler\Block $block): void
    {
        $paramNames = array_values($block->paramNames);
        $required = 0;
        for ($i = 0, $n = \count($paramNames); $i < $n; ++$i) {
            if (ReflectionSupport::parameterIsVariadic($block, $i)
                || ParamArgumentCountError::parameterHasDefault($block, $i)
            ) {
                break;
            }
            ++$required;
        }
        self::recordUserMethod($className, $methodName, \count($paramNames), $required);
    }

    public static function implementLookupFunctions(Context $context): void
    {
        $userMethods = self::filterUserScriptMethods(self::$userMethods);
        self::resetAccumulated();

        $visibility = self::buildVisibilityMap($context, $userMethods);
        $paramCounts = self::buildParamCountMaps($context, $userMethods);

        ReflectionMethodQueryLookupRuntime::implement(
            $context,
            self::encodeMap($visibility),
            self::encodeMap($paramCounts['total']),
            self::encodeMap($paramCounts['required'])
        );
        $context->builder->clearInsertionPosition();
    }

    public static function visibilityMapForContext(Context $context): array
    {
        return self::buildVisibilityMap(
            $context,
            self::filterUserScriptMethods(self::$userMethods)
        );
    }

    /**
     * @return array{total: array<string, array<string, int>>, required: array<string, array<string, int>>}
     */
    public static function paramCountMapsForContext(Context $context): array
    {
        return self::buildParamCountMaps(
            $context,
            self::filterUserScriptMethods(self::$userMethods)
        );
    }

    /**
     * Resolve method metadata at JIT compile time when class/method are literals (#34216).
     *
     * @return array{flags: int, total: int, required: int}|null
     */
    public static function compileTimeMethodMetadata(
        Context $context,
        string $className,
        string $methodName
    ): ?array {
        $classLc = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);
        if ('' === $classLc || '' === $methodLc) {
            return null;
        }
        $visibility = self::visibilityMapForContext($context);
        $paramMaps = self::paramCountMapsForContext($context);
        $flags = self::mapLookup($visibility, $classLc, $methodLc);
        if (null === $flags) {
            return null;
        }
        $total = self::mapLookup($paramMaps['total'], $classLc, $methodLc);
        $required = self::mapLookup($paramMaps['required'], $classLc, $methodLc);
        if (null === $total || null === $required) {
            return null;
        }

        return [
            'flags' => $flags,
            'total' => $total,
            'required' => $required,
        ];
    }

    /**
     * @param array<string, array<string, int>> $map
     */
    private static function mapLookup(array $map, string $classLc, string $methodLc): ?int
    {
        foreach ($map as $classKey => $methods) {
            if (strtolower(ltrim((string) $classKey, '\\')) !== $classLc) {
                continue;
            }
            if (!isset($methods[$methodLc])) {
                return null;
            }

            return (int) $methods[$methodLc];
        }

        return null;
    }

    public static function resetAccumulated(): void
    {
        self::$userMethods = [];
    }

    /**
     * @param array<string, array<string, array{total: int, required: int}>> $recorded
     *
     * @return array<string, array<string, array{total: int, required: int}>>
     */
    private static function filterUserScriptMethods(array $recorded): array
    {
        $out = [];
        foreach ($recorded as $classLc => $methods) {
            if (!self::isUserScriptClassLc((string) $classLc)) {
                continue;
            }
            $out[$classLc] = $methods;
        }

        return $out;
    }

    private static function isUserScriptClassLc(string $classLc): bool
    {
        return !str_starts_with($classLc, 'phpcompiler\\');
    }

    /**
     * @param array<string, array<string, array{total: int, required: int}>> $userRecorded
     *
     * @return array<string, array<string, int>>
     */
    private static function buildVisibilityMap(Context $context, array $userRecorded): array
    {
        $out = [];
        $object = $context->type->object;
        foreach ($userRecorded as $classLc => $methods) {
            $classId = $object->classIdForLowerName($classLc);
            if (null === $classId) {
                continue;
            }
            $display = $object->classNameForId($classId);
            if (!\is_string($display) || '' === $display) {
                $display = $classLc;
            }
            $classDisplay = ltrim($display, '\\');
            foreach (array_keys($methods) as $methodLc) {
                $flags = $object->methodVisibility($classId, (string) $methodLc);
                $out[$classDisplay][(string) $methodLc] = $flags;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, array{total: int, required: int}>> $userRecorded
     *
     * @return array{total: array<string, array<string, int>>, required: array<string, array<string, int>>}
     */
    private static function buildParamCountMaps(Context $context, array $userRecorded): array
    {
        $total = [];
        $required = [];
        $object = $context->type->object;

        foreach ($userRecorded as $classLc => $methods) {
            $classId = $object->classIdForLowerName($classLc);
            if (null === $classId) {
                continue;
            }
            $display = $object->classNameForId($classId);
            if (!\is_string($display) || '' === $display) {
                $display = $classLc;
            }
            $classDisplay = ltrim($display, '\\');
            foreach ($methods as $methodLc => $counts) {
                $methodLc = (string) $methodLc;
                $total[$classDisplay][$methodLc] = $counts['total'];
                $required[$classDisplay][$methodLc] = $counts['required'];
            }
        }

        return ['total' => $total, 'required' => $required];
    }

    /** @param array<string, array<string, int>> $map */
    private static function encodeMap(array $map): string
    {
        if ([] === $map) {
            return '{}';
        }

        return (string) json_encode($map, JSON_THROW_ON_ERROR);
    }
}
