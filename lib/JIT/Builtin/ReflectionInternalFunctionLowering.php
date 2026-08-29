<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinInternalDefaultValues;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT\Context;

/**
 * Record internal free functions referenced via Reflection* literal names for thin AOT (#28780).
 *
 * Peer of {@see ReflectionFunctionParamCountLowering} (#34218).
 */
final class ReflectionInternalFunctionLowering
{
    /** @var array<string, true> */
    private static array $functions = [];

    public static function recordFunction(string $funcLc): void
    {
        $lc = strtolower(trim($funcLc));
        if ('' === $lc) {
            return;
        }
        self::$functions[$lc] = true;
        $ret = BuiltinInternalArgInfo::returnTypeLabelForFunction($lc);
        if (null !== $ret && '' !== trim($ret)) {
            ReflectionTypeJitHelper::noteLabel(trim($ret));
        }
        $names = BuiltinParamNames::paramNamesForInternalFunction($lc);
        if (null === $names) {
            $names = BuiltinParamNames::forFunction($lc);
        }
        if (null === $names) {
            return;
        }
        foreach (array_keys($names) as $index) {
            $info = BuiltinInternalArgInfo::paramInfoForFunction($lc, (int) $index);
            $type = null !== $info ? trim((string) ($info['type'] ?? '')) : '';
            if ('' !== $type) {
                ReflectionTypeJitHelper::noteLabel($type);
            }
        }
    }

    /** @return array<string, true> */
    public static function recordedFunctions(): array
    {
        return self::$functions;
    }

    public static function resetAccumulated(): void
    {
        self::$functions = [];
    }

    public static function implementLookupFunctions(Context $context): void
    {
        $recorded = self::$functions;
        self::resetAccumulated();
        if ([] === $recorded) {
            return;
        }

        $metadata = [];
        foreach (array_keys($recorded) as $funcLc) {
            $names = BuiltinParamNames::paramNamesForInternalFunction($funcLc);
            if (null === $names) {
                $names = BuiltinParamNames::forFunction($funcLc);
            }
            if (null === $names) {
                continue;
            }
            $params = [];
            foreach (array_keys($names) as $index) {
                $info = BuiltinInternalArgInfo::paramInfoForFunction($funcLc, (int) $index);
                $type = null !== $info ? trim((string) ($info['type'] ?? '')) : '';
                $params[(int) $index] = [
                    'name' => self::displayParamName((string) $names[$index]),
                    'type' => '' !== $type ? $type : null,
                    'hasDefault' => self::internalParamHasDefault($funcLc, (int) $index),
                ];
            }
            $ret = BuiltinInternalArgInfo::returnTypeLabelForFunction($funcLc);
            $metadata[$funcLc] = [
                'params' => $params,
                'return' => null !== $ret && '' !== trim($ret) ? trim($ret) : null,
            ];
        }

        if ([] === $metadata) {
            return;
        }

        foreach ($metadata as $entry) {
            if (null !== ($entry['return'] ?? null)) {
                ReflectionTypeJitHelper::noteLabel((string) $entry['return']);
            }
            foreach ($entry['params'] as $param) {
                if (null !== ($param['type'] ?? null)) {
                    ReflectionTypeJitHelper::noteLabel((string) $param['type']);
                }
            }
        }

        ReflectionInternalFunctionLookupRuntime::implement(
            $context,
            (string) json_encode($metadata, JSON_THROW_ON_ERROR)
        );
        ReflectionTypeFromLabelLookupRuntime::implement(
            $context,
            ReflectionTypeJitHelper::knownLabels()
        );
        $context->builder->clearInsertionPosition();
    }

    private static function displayParamName(string $raw): string
    {
        $display = ltrim($raw, '&');
        if (str_starts_with($display, '...')) {
            $display = substr($display, 3);
        }

        return rtrim($display, '=');
    }

    private static function internalParamHasDefault(string $funcLc, int $index): bool
    {
        $info = BuiltinInternalArgInfo::paramInfoForFunction($funcLc, $index);
        $isVariadic = !empty($info['isVariadic']);

        return BuiltinInternalDefaultValues::isAvailable($funcLc, $index, $info, $isVariadic);
    }
}
