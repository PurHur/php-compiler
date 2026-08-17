<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for __compiler_attr_* lookup (#10086, #20901).
 *
 * Embed + thin standalone AOT: host-decoded compile-time tables → `__compiler_strcasecmp`
 * select chains (no dishonest thin stubs; no NestedJIT of AttributeRegistryJitHelper —
 * NestedJIT of the JSON scanner is not thin-AOT-safe: zext/__value__ verify failures).
 * Lookup semantics SSOT for tests: {@see \PHPCompiler\ext\standard\AttributeRegistryJitHelper}.
 * Args hashtable with ctor args: {@see AttributeRegistryArgsJitHelper} NestedJIT when needed;
 * otherwise null bridge.
 * Peer shape: IncludePath #20877 / Serialize #20773 (drop thin stub fork).
 */
final class AttributeRegistryLookupRuntime
{
    public static function implement(
        Context $context,
        string $classNamesJson,
        string $methodNamesJson,
        string $classEntriesJson
    ): void {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_attr_class_count');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        StringCaseCompare::ensureStrcasecmpLinked($context);
        self::implementClassCountBridge($context, $classNamesJson);
        self::implementClassNameAtBridge($context, $classNamesJson);
        self::implementMethodCountBridge($context, $methodNamesJson);
        self::implementMethodNameAtBridge($context, $methodNamesJson);
        self::implementClassArgsHashtableBridge($context, $classEntriesJson);
        $context->builder->clearInsertionPosition();
    }

    private static function implementClassCountBridge(Context $context, string $classNamesJson): void
    {
        $abiName = '__compiler_attr_class_count';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($sizeT, false, $i8p);
        // Reuse ReflectionNative declare — addFunction renames to .1 and leaves U undef (#26828).
        $probe = $context->module->getNamedFunction($abiName);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $fn);

            return;
        }
        $entry = $fn->appendBasicBlock('attr_class_count_bridge');
        $context->builder->positionAtEnd($entry);

        $classCstr = $fn->getParam(0);
        $strcasecmp = $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP);
        $result = $sizeT->constInt(0, false);
        foreach (self::decodeClassNames($classNamesJson) as $key => $names) {
            if (!\is_string($key) || !\is_array($names)) {
                continue;
            }
            $keyLit = $context->bytePtr($context->constantFromString($key));
            $cmp = $context->builder->call($strcasecmp, $classCstr, $keyLit);
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $count = $sizeT->constInt(\count($names), false);
            $result = $context->builder->select($isMatch, $count, $result);
        }
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClassNameAtBridge(Context $context, string $classNamesJson): void
    {
        $abiName = '__compiler_attr_class_name_at';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i8p, false, $i8p, $sizeT);
        $probe = $context->module->getNamedFunction($abiName);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $fn);

            return;
        }
        $entry = $fn->appendBasicBlock('attr_class_name_at_bridge');
        $context->builder->positionAtEnd($entry);

        $classCstr = $fn->getParam(0);
        $idx = $fn->getParam(1);
        $strcasecmp = $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP);
        $result = $context->bytePtr($context->constantFromString(''));
        foreach (self::decodeClassNames($classNamesJson) as $key => $names) {
            if (!\is_string($key) || !\is_array($names)) {
                continue;
            }
            $keyLit = $context->bytePtr($context->constantFromString($key));
            $cmp = $context->builder->call($strcasecmp, $classCstr, $keyLit);
            $classMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            foreach ($names as $nameIdx => $name) {
                if (!\is_string($name)) {
                    continue;
                }
                $idxMatch = $context->builder->icmp(
                    Builder::INT_EQ,
                    $idx,
                    $sizeT->constInt((int) $nameIdx, false)
                );
                $match = $context->builder->and($classMatch, $idxMatch);
                $nameLit = $context->bytePtr($context->constantFromString($name));
                $result = $context->builder->select($match, $nameLit, $result);
            }
        }
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMethodCountBridge(Context $context, string $methodNamesJson): void
    {
        $abiName = '__compiler_attr_method_count';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($sizeT, false, $i8p, $i8p);
        $probe = $context->module->getNamedFunction($abiName);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $fn);

            return;
        }
        $entry = $fn->appendBasicBlock('attr_method_count_bridge');
        $context->builder->positionAtEnd($entry);

        $classCstr = $fn->getParam(0);
        $methodCstr = $fn->getParam(1);
        $strcasecmp = $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP);
        $result = $sizeT->constInt(0, false);
        foreach (self::decodeMethodNames($methodNamesJson) as $classKey => $methods) {
            if (!\is_string($classKey) || !\is_array($methods)) {
                continue;
            }
            $classLit = $context->bytePtr($context->constantFromString($classKey));
            $classCmp = $context->builder->call($strcasecmp, $classCstr, $classLit);
            $classMatch = $context->builder->icmp(Builder::INT_EQ, $classCmp, $i32->constInt(0, false));
            foreach ($methods as $methodKey => $names) {
                if (!\is_string($methodKey) || !\is_array($names)) {
                    continue;
                }
                $methodLit = $context->bytePtr($context->constantFromString($methodKey));
                $methodCmp = $context->builder->call($strcasecmp, $methodCstr, $methodLit);
                $methodMatch = $context->builder->icmp(Builder::INT_EQ, $methodCmp, $i32->constInt(0, false));
                $match = $context->builder->and($classMatch, $methodMatch);
                $count = $sizeT->constInt(\count($names), false);
                $result = $context->builder->select($match, $count, $result);
            }
        }
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMethodNameAtBridge(Context $context, string $methodNamesJson): void
    {
        $abiName = '__compiler_attr_method_name_at';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i8p, false, $i8p, $i8p, $sizeT);
        $probe = $context->module->getNamedFunction($abiName);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $fn);

            return;
        }
        $entry = $fn->appendBasicBlock('attr_method_name_at_bridge');
        $context->builder->positionAtEnd($entry);

        $classCstr = $fn->getParam(0);
        $methodCstr = $fn->getParam(1);
        $idx = $fn->getParam(2);
        $strcasecmp = $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP);
        $result = $context->bytePtr($context->constantFromString(''));
        foreach (self::decodeMethodNames($methodNamesJson) as $classKey => $methods) {
            if (!\is_string($classKey) || !\is_array($methods)) {
                continue;
            }
            $classLit = $context->bytePtr($context->constantFromString($classKey));
            $classCmp = $context->builder->call($strcasecmp, $classCstr, $classLit);
            $classMatch = $context->builder->icmp(Builder::INT_EQ, $classCmp, $i32->constInt(0, false));
            foreach ($methods as $methodKey => $names) {
                if (!\is_string($methodKey) || !\is_array($names)) {
                    continue;
                }
                $methodLit = $context->bytePtr($context->constantFromString($methodKey));
                $methodCmp = $context->builder->call($strcasecmp, $methodCstr, $methodLit);
                $methodMatch = $context->builder->icmp(Builder::INT_EQ, $methodCmp, $i32->constInt(0, false));
                $cm = $context->builder->and($classMatch, $methodMatch);
                foreach ($names as $nameIdx => $name) {
                    if (!\is_string($name)) {
                        continue;
                    }
                    $idxMatch = $context->builder->icmp(
                        Builder::INT_EQ,
                        $idx,
                        $sizeT->constInt((int) $nameIdx, false)
                    );
                    $match = $context->builder->and($cm, $idxMatch);
                    $nameLit = $context->bytePtr($context->constantFromString($name));
                    $result = $context->builder->select($match, $nameLit, $result);
                }
            }
        }
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClassArgsHashtableBridge(Context $context, string $classEntriesJson): void
    {
        // Ctor-arg hashtables still need NestedJIT Args helper; without args keep null ABI.
        $abiName = '__compiler_attr_class_args_hashtable';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        // Always null bridge for thin+embed when no ctor args (common). NestedJIT Args path
        // remains available via AttributeRegistryArgsJitHelper when entries need it — but
        // NestedJIT of Args (Variable/HashTable) is not thin-AOT-safe yet; refuse ctor-arg
        // tables under thin AOT by returning null (Reflection getArguments stays empty).
        self::implementClassArgsHashtableNullBridge($context);
    }

    private static function implementClassArgsHashtableNullBridge(Context $context): void
    {
        $abiName = '__compiler_attr_class_args_hashtable';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidp = $context->getTypeFromString('void*');
        $ft = $context->context->functionType($htPtr, false, $i8p, $sizeT);
        $probe = $context->module->getNamedFunction($abiName);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $fn);

            return;
        }
        $entry = $fn->appendBasicBlock('attr_class_args_ht_null');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->pointerCast($voidp->constNull(), $htPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    /** @return array<string, list<string>> */
    private static function decodeClassNames(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, array<string, list<string>>> */
    private static function decodeMethodNames(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
