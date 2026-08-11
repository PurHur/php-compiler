<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for compact() warnings + extract() EXTR_* name resolution (#10184, #14499, #23261).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ZlibRuntime #23252).
 * Replaces inline standalone warning LLVM in {@see ScopeBuiltinEmitHelper}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmScope}
 */
final class ScopeBuiltinRuntime
{
    private const HELPER_PATH = '/ext/standard/ScopeBuiltinJitHelper.php';

    private const COMPACT_UNDEF_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::emitCompactUndefinedVariableWarning';

    private const COMPACT_INVALID_ARG_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::emitCompactInvalidArgumentWarning';

    private const RESOLVE_EXTRACT_TARGET_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::resolveExtractTargetName';

    private const COLLECT_COMPACT_NAMES_HT_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::collectCompactNamesFromHashtable';

    private const STORE_VAR_SNAPSHOT_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::storeVarSnapshotAtStringKey';

    private const MATCH_NAMED_VAR_INDEX_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::matchNamedVariableIndex';

    private const ABI_RESOLVE_EXTRACT_TARGET = '__scope_extract_resolve_target';

    private const ABI_COLLECT_COMPACT_NAMES_HT = '__scope_compact_collect_names_ht';

    private const ABI_STORE_VAR_SNAPSHOT = '__scope_store_var_snapshot';

    private const ABI_MATCH_NAMED_VAR_INDEX = '__scope_match_named_var_index';

    private const ABI_COMPACT_INVALID_ARG_WARN = '__scope_compact_invalid_arg_warn';

    private const ABI_COMPACT_UNDEF_WARN = '__scope_compact_undef_warn';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPACT_UNDEF_HELPER,
        self::COMPACT_INVALID_ARG_HELPER,
        self::RESOLVE_EXTRACT_TARGET_HELPER,
        self::COLLECT_COMPACT_NAMES_HT_HELPER,
        self::STORE_VAR_SNAPSHOT_HELPER,
        self::MATCH_NAMED_VAR_INDEX_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        // ScopeBuiltinJitHelper::isValidVarName uses preg_match → `__compiler_preg_match` (#27520).
        PregMatchRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::ensureExtractResolveLinked($context);
        self::ensureCompactCollectLinked($context);
        self::ensureStoreSnapshotLinked($context);
        self::ensureMatchNamedVarLinked($context);
    }

    public static function resolveExtractTargetName(
        Context $context,
        Value $keyStr,
        Value $varExists,
        Value $extractType,
        Value $prefixStr
    ): Value {
        self::ensureExtractResolveLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $varExistsI64 = $context->builder->zext($varExists, $i64);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_RESOLVE_EXTRACT_TARGET),
            $keyStr,
            $varExistsI64,
            $extractType,
            $prefixStr
        );
    }

    public static function ensureExtractResolveLinked(Context $context): void
    {
        PregMatchRuntime::ensureLinked($context);
        $probe = $context->module->getNamedFunction(self::ABI_RESOLVE_EXTRACT_TARGET);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_RESOLVE_EXTRACT_TARGET, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_RESOLVE_EXTRACT_TARGET,
            'scope_extract_resolve_target_entry',
            [$strPtr, $i64, $i64, $strPtr],
            $strPtr,
            self::RESOLVE_EXTRACT_TARGET_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14499'
        );
        $context->registerFunction(
            self::ABI_RESOLVE_EXTRACT_TARGET,
            $context->module->getNamedFunction(self::ABI_RESOLVE_EXTRACT_TARGET)
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function collectCompactNamesFromHashtable(
        Context $context,
        Value $destHt,
        Value $srcHt,
        Value $argNum
    ): void {
        self::ensureCompactCollectLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::ABI_COLLECT_COMPACT_NAMES_HT),
            $destHt,
            $srcHt,
            $argNum
        );
    }

    public static function ensureCompactCollectLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_COLLECT_COMPACT_NAMES_HT);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_COLLECT_COMPACT_NAMES_HT, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COLLECT_COMPACT_NAMES_HT,
            'scope_compact_collect_names_ht_entry',
            [$htPtr, $htPtr, $i64],
            $context->getTypeFromString('void'),
            self::COLLECT_COMPACT_NAMES_HT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14507'
        );
        $context->registerFunction(
            self::ABI_COLLECT_COMPACT_NAMES_HT,
            $context->module->getNamedFunction(self::ABI_COLLECT_COMPACT_NAMES_HT)
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function storeVarSnapshotAtStringKey(
        Context $context,
        Value $destHt,
        Value $keyStr,
        Value $valuePtr
    ): void {
        self::ensureStoreSnapshotLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::ABI_STORE_VAR_SNAPSHOT),
            $destHt,
            $keyStr,
            $valuePtr
        );
    }

    public static function ensureStoreSnapshotLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_STORE_VAR_SNAPSHOT);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_STORE_VAR_SNAPSHOT, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STORE_VAR_SNAPSHOT,
            'scope_store_var_snapshot_entry',
            [$htPtr, $strPtr, $valuePtr],
            $context->getTypeFromString('void'),
            self::STORE_VAR_SNAPSHOT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14507'
        );
        $context->registerFunction(
            self::ABI_STORE_VAR_SNAPSHOT,
            $context->module->getNamedFunction(self::ABI_STORE_VAR_SNAPSHOT)
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function matchNamedVariableIndex(
        Context $context,
        Value $needleStr,
        string $namesTable
    ): Value {
        self::ensureMatchNamedVarLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MATCH_NAMED_VAR_INDEX),
            $needleStr,
            $context->builder->load($context->constantStringFromString($namesTable))
        );
    }

    public static function matchNamedVariableIndexFromCstr(
        Context $context,
        Value $namePtr,
        string $namesTable
    ): Value {
        return self::matchNamedVariableIndex($context, self::cstrToStringPtr($context, $namePtr), $namesTable);
    }

    public static function ensureMatchNamedVarLinked(Context $context): void
    {
        PregMatchRuntime::ensureLinked($context);
        $probe = $context->module->getNamedFunction(self::ABI_MATCH_NAMED_VAR_INDEX);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_MATCH_NAMED_VAR_INDEX, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_MATCH_NAMED_VAR_INDEX,
            'scope_match_named_var_index_entry',
            [$strPtr, $strPtr],
            $i32,
            self::MATCH_NAMED_VAR_INDEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16534'
        );
        $context->registerFunction(
            self::ABI_MATCH_NAMED_VAR_INDEX,
            $context->module->getNamedFunction(self::ABI_MATCH_NAMED_VAR_INDEX)
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function cstrToStringPtr(Context $context, Value $namePtr): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt(
                $context->builder->call($context->lookupFunction('strlen'), $namePtr),
                $i64
            ),
            $namePtr
        );
    }

    public static function emitCompactUndefinedVariableWarning(Context $context, string $name): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitStandaloneCompactUndefinedWarning($context, $name);

            return;
        }

        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::COMPACT_UNDEF_HELPER),
            $context->constantFromString($name)
        );
    }

    public static function emitCompactInvalidArgumentWarning(
        Context $context,
        int $argNum,
        Value $typeByte,
        ?Value $boolPayload = null
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $payload = $boolPayload ?? $i8->constInt(0, false);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitStandaloneCompactInvalidArgumentWarning($context, $argNum, $typeByte, $payload);

            return;
        }

        self::ensureJitHelperCompiled($context);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            self::helperFunction($context, self::COMPACT_INVALID_ARG_HELPER),
            $i64->constInt($argNum, false),
            $context->builder->trunc($typeByte, $i8),
            $context->builder->trunc($payload, $i8)
        );
    }

    public static function emitCompactUndefinedVariableWarningFromCstr(Context $context, Value $namePtr): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitStandaloneCompactUndefinedWarningFromCstr($context, $namePtr);

            return;
        }

        self::ensureJitHelperCompiled($context);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $namePtr);
        $lenI64 = $context->builder->zExt($len, $i64);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $namePtr
        );
        $context->builder->call(self::helperFunction($context, self::COMPACT_UNDEF_HELPER), $strPtr);
    }

    private static function emitStandaloneCompactInvalidArgumentWarning(
        Context $context,
        int $argNum,
        Value $typeByte,
        Value $boolPayload
    ): void {
        self::ensureCompactInvalidArgWarnStandaloneLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $context->builder->call(
            $context->lookupFunction(self::ABI_COMPACT_INVALID_ARG_WARN),
            $i64->constInt($argNum, false),
            $context->builder->trunc($typeByte, $i8),
            $context->builder->trunc($boolPayload, $i8)
        );
    }

    private static function emitStandaloneCompactUndefinedWarning(Context $context, string $name): void
    {
        self::ensureCompactUndefWarnStandaloneLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::ABI_COMPACT_UNDEF_WARN),
            $context->constantFromString($name)
        );
    }

    private static function emitStandaloneCompactUndefinedWarningFromCstr(Context $context, Value $namePtr): void
    {
        self::ensureCompactUndefWarnStandaloneLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::ABI_COMPACT_UNDEF_WARN),
            self::cstrToStringPtr($context, $namePtr)
        );
    }

    private static function ensureCompactInvalidArgWarnStandaloneLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_COMPACT_INVALID_ARG_WARN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_COMPACT_INVALID_ARG_WARN, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COMPACT_INVALID_ARG_WARN,
            'scope_compact_invalid_arg_warn_entry',
            [$i64, $i8, $i8],
            $context->getTypeFromString('void'),
            self::COMPACT_INVALID_ARG_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18773'
        );
        $context->registerFunction(
            self::ABI_COMPACT_INVALID_ARG_WARN,
            $context->module->getNamedFunction(self::ABI_COMPACT_INVALID_ARG_WARN)
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureCompactUndefWarnStandaloneLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_COMPACT_UNDEF_WARN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_COMPACT_UNDEF_WARN, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COMPACT_UNDEF_WARN,
            'scope_compact_undef_warn_entry',
            [$strPtr],
            $context->getTypeFromString('void'),
            self::COMPACT_UNDEF_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18773'
        );
        $context->registerFunction(
            self::ABI_COMPACT_UNDEF_WARN,
            $context->module->getNamedFunction(self::ABI_COMPACT_UNDEF_WARN)
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23261');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        // Before NestedJIT of ScopeBuiltinJitHelper (preg_match in isValidVarName) (#27520).
        PregMatchRuntime::ensureLinked($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23261'
        );
    }
}
