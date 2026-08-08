<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for libxml error-buffer builtins (#28659, #29161).
 *
 * Thin standalone AOT keeps the error ring in module globals (NestedJIT statics are not
 * shared across seed vs get_errors call sites). JIT/embed still uses NestedJIT → VmLibxml.
 *
 * php-src: ext/libxml/libxml.c
 */
final class LibxmlUseInternalErrorsRuntime
{
    private const HELPER_PATH = '/ext/libxml/LibxmlInternalErrorsJitHelper.php';

    private const EXCHANGE_HELPER = 'PHPCompiler\\ext\\libxml\\LibxmlInternalErrorsJitHelper::exchange';

    private const CLEAR_HELPER = 'PHPCompiler\\ext\\libxml\\LibxmlInternalErrorsJitHelper::clear';

    private const GET_ERRORS_HELPER = 'PHPCompiler\\ext\\libxml\\LibxmlInternalErrorsJitHelper::getErrorsHt';

    private const GET_LAST_HELPER = 'PHPCompiler\\ext\\libxml\\LibxmlInternalErrorsJitHelper::getLastErrorObject';

    private const RECORD_SCALARS_HELPER = 'PHPCompiler\\ext\\libxml\\LibxmlInternalErrorsJitHelper::recordScalars';

    private const MAX_AOT_ERRORS = 8;

    private const G_COUNT = '__compiler_libxml_aot_err_count';

    private const G_LEVEL = '__compiler_libxml_aot_err_level';

    private const G_CODE = '__compiler_libxml_aot_err_code';

    private const G_COLUMN = '__compiler_libxml_aot_err_column';

    private const G_LINE = '__compiler_libxml_aot_err_line';

    private const G_MESSAGE = '__compiler_libxml_aot_err_message';

    private const G_FILE = '__compiler_libxml_aot_err_file';

    /** @var list<string> */
    private const SCALAR_HELPERS = [
        self::EXCHANGE_HELPER,
        self::CLEAR_HELPER,
        self::RECORD_SCALARS_HELPER,
    ];

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXCHANGE_HELPER,
        self::CLEAR_HELPER,
        self::GET_ERRORS_HELPER,
        self::GET_LAST_HELPER,
        self::RECORD_SCALARS_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_libxml_use_internal_errors',
        '__compiler_libxml_clear_errors',
        '__compiler_libxml_get_errors',
        '__compiler_libxml_get_last_error',
        '__compiler_libxml_record_error',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_libxml_use_internal_errors');
        $clearProbe = $context->module->getNamedFunction('__compiler_libxml_clear_errors');
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && null !== $clearProbe && $clearProbe->countBasicBlocks() > 0
        ) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        NestedVmActiveContextLlvm::ensureMethod($context);
        self::ensureJitHelperCompiled($context);
        self::ensureAotRingGlobals($context);
        self::implementExchangeBridge($context);
        self::implementClearBridge($context);
        self::implementGetErrorsBridge($context);
        self::implementGetLastErrorBridge($context);
        self::implementRecordBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Thin AOT: write host diagnostics into LLVM ring globals (#29161). */
    public static function emitSeedErrors(Context $context, array $rows): void
    {
        self::ensureLinked($context);
        self::emitAotClear($context);
        $i = 0;
        foreach ($rows as $row) {
            if ($i >= self::MAX_AOT_ERRORS) {
                break;
            }
            self::emitAotStoreAt($context, $i, $row);
            ++$i;
        }
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt($i, true),
            self::global($context, self::G_COUNT)
        );
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $record
     */
    public static function emitRecordError(Context $context, array $record): void
    {
        // Kept for call sites that append one error; prefer emitSeedErrors for batches.
        self::ensureLinked($context);
        $countPtr = self::global($context, self::G_COUNT);
        $count = $context->builder->load($countPtr);
        $i64 = $context->getTypeFromString('int64');
        $max = $i64->constInt(self::MAX_AOT_ERRORS, true);
        $inRange = $context->builder->icmp(Builder::INT_SLT, $count, $max);
        $takeBb = BasicBlockHelper::append($context, 'libxml_aot_record_take');
        $doneBb = BasicBlockHelper::append($context, 'libxml_aot_record_done');
        $context->builder->branchIf($inRange, $takeBb, $doneBb);
        $context->builder->positionAtEnd($takeBb);
        // Store at runtime index in count (unrolled store helpers need const index — use seed path).
        self::emitAotStoreAtRuntimeIndex($context, $count, $record);
        $context->builder->store($context->builder->add($count, $i64->constInt(1, true)), $countPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    public static function isStandalone(Context $context): bool
    {
        return Builtin::LOAD_TYPE_STANDALONE === $context->loadType;
    }

    /** Emit LibXMLError[] body into the current insert block (ABI fn; hrtime_pair peer). */
    private static function emitBuildErrorsHtFromRing(Context $context): Value
    {
        self::ensureLibXmlErrorClass($context);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load(self::global($context, self::G_COUNT));

        for ($i = 0; $i < self::MAX_AOT_ERRORS; ++$i) {
            $idx = $i64->constInt($i, true);
            $inRange = $context->builder->icmp(Builder::INT_SLT, $idx, $n);
            $takeBb = BasicBlockHelper::append($context, 'libxml_aot_err_take_'.$i);
            $skipBb = BasicBlockHelper::append($context, 'libxml_aot_err_skip_'.$i);
            $context->builder->branchIf($inRange, $takeBb, $skipBb);

            $context->builder->positionAtEnd($takeBb);
            $obj = self::materializeErrorAtConst($context, $i);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setObjectAt'),
                $ht,
                $sizeT->constInt($i, false),
                $obj
            );
            $context->builder->branch($skipBb);
            $context->builder->positionAtEnd($skipBb);
        }

        return $ht;
    }

    /** Emit last LibXMLError* (or null) into the current insert block. */
    private static function emitBuildLastErrorFromRing(Context $context): Value
    {
        self::ensureLibXmlErrorClass($context);
        $i64 = $context->getTypeFromString('int64');
        $objTy = $context->getTypeFromString('__object__*');
        $slot = $context->builder->alloca($objTy);
        $context->builder->store($objTy->constNull(), $slot);

        $n = $context->builder->load(self::global($context, self::G_COUNT));
        $empty = $context->builder->icmp(Builder::INT_EQ, $n, $i64->constInt(0, true));
        $emptyBb = BasicBlockHelper::append($context, 'libxml_aot_last_empty');
        $objBb = BasicBlockHelper::append($context, 'libxml_aot_last_obj');
        $doneBb = BasicBlockHelper::append($context, 'libxml_aot_last_done');
        $context->builder->branchIf($empty, $emptyBb, $objBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($objBb);
        for ($i = 0; $i < self::MAX_AOT_ERRORS; ++$i) {
            $want = $context->builder->icmp(
                Builder::INT_EQ,
                $n,
                $i64->constInt($i + 1, true)
            );
            $matchBb = BasicBlockHelper::append($context, 'libxml_aot_last_match_'.$i);
            $nextBb = BasicBlockHelper::append($context, 'libxml_aot_last_next_'.$i);
            $context->builder->branchIf($want, $matchBb, $nextBb);
            $context->builder->positionAtEnd($matchBb);
            $context->builder->store(self::materializeErrorAtConst($context, $i), $slot);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($nextBb);
        }
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($slot);
    }

    private static function emitAotClear(Context $context): void
    {
        $context->builder->call($context->lookupFunction('__compiler_libxml_clear_errors'));
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, true),
            self::global($context, self::G_COUNT)
        );
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $row
     */
    private static function emitAotStoreAt(Context $context, int $i, array $row): void
    {
        $i64 = $context->getTypeFromString('int64');
        $idx = $i64->constInt($i, true);
        self::storeIntAt($context, self::G_LEVEL, $idx, (int) $row['level']);
        self::storeIntAt($context, self::G_CODE, $idx, (int) $row['code']);
        self::storeIntAt($context, self::G_COLUMN, $idx, (int) $row['column']);
        self::storeIntAt($context, self::G_LINE, $idx, (int) $row['line']);
        self::storeStrAt($context, self::G_MESSAGE, $idx, (string) $row['message']);
        self::storeStrAt($context, self::G_FILE, $idx, (string) $row['file']);
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $row
     */
    private static function emitAotStoreAtRuntimeIndex(Context $context, Value $idx, array $row): void
    {
        self::storeIntAt($context, self::G_LEVEL, $idx, (int) $row['level']);
        self::storeIntAt($context, self::G_CODE, $idx, (int) $row['code']);
        self::storeIntAt($context, self::G_COLUMN, $idx, (int) $row['column']);
        self::storeIntAt($context, self::G_LINE, $idx, (int) $row['line']);
        self::storeStrAt($context, self::G_MESSAGE, $idx, (string) $row['message']);
        self::storeStrAt($context, self::G_FILE, $idx, (string) $row['file']);
    }

    private static function storeIntAt(Context $context, string $globalName, Value $idx, int $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $arr = self::global($context, $globalName);
        $slot = $context->builder->inBoundsGep($arr, $i64->constInt(0, true), $idx);
        $context->builder->store($i64->constInt($value, true), $slot);
    }

    private static function storeStrAt(Context $context, string $globalName, Value $idx, string $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $arr = self::global($context, $globalName);
        $slot = $context->builder->inBoundsGep($arr, $i64->constInt(0, true), $idx);
        $str = $context->builder->load($context->constantStringFromString($value));
        $context->builder->store($str, $slot);
    }

    private static function materializeErrorAtConst(Context $context, int $i): Value
    {
        $objectType = $context->type->object;
        $classId = self::ensureLibXmlErrorClass($context);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $className = 'LibXMLError';
        $i64 = $context->getTypeFromString('int64');
        $idx = $i64->constInt($i, true);

        $level = $context->builder->load(
            $context->builder->inBoundsGep(self::global($context, self::G_LEVEL), $i64->constInt(0, true), $idx)
        );
        $code = $context->builder->load(
            $context->builder->inBoundsGep(self::global($context, self::G_CODE), $i64->constInt(0, true), $idx)
        );
        $column = $context->builder->load(
            $context->builder->inBoundsGep(self::global($context, self::G_COLUMN), $i64->constInt(0, true), $idx)
        );
        $line = $context->builder->load(
            $context->builder->inBoundsGep(self::global($context, self::G_LINE), $i64->constInt(0, true), $idx)
        );
        $message = $context->builder->load(
            $context->builder->inBoundsGep(self::global($context, self::G_MESSAGE), $i64->constInt(0, true), $idx)
        );
        $file = $context->builder->load(
            $context->builder->inBoundsGep(self::global($context, self::G_FILE), $i64->constInt(0, true), $idx)
        );

        foreach (['level' => $level, 'code' => $code, 'column' => $column, 'line' => $line] as $prop => $val) {
            $propVar = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $val);
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, $className, $prop),
                $propVar,
                JITVariable::TYPE_NATIVE_LONG
            );
        }
        foreach (['message' => $message, 'file' => $file] as $prop => $str) {
            $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
            $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, $className, $prop),
                $propVar,
                JITVariable::TYPE_STRING
            );
        }

        return $obj;
    }

    private static function ensureLibXmlErrorClass(Context $context): int
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('LibXMLError');
        foreach (['level', 'code', 'column', 'line'] as $prop) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, JITVariable::TYPE_NATIVE_LONG);
            }
        }
        foreach (['message', 'file'] as $prop) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, JITVariable::TYPE_STRING);
            }
        }

        return $classId;
    }

    private static function ensureAotRingGlobals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === $context->module->getNamedGlobal(self::G_COUNT)) {
            $g = $context->module->addGlobal($i64, self::G_COUNT);
            $g->setInitializer($i64->constInt(0, true));
        }
        $arrI64 = $i64->arrayType(self::MAX_AOT_ERRORS);
        $arrStr = $strPtr->arrayType(self::MAX_AOT_ERRORS);
        foreach ([self::G_LEVEL, self::G_CODE, self::G_COLUMN, self::G_LINE] as $name) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($arrI64, $name);
                $g->setInitializer($arrI64->constNull());
            }
        }
        foreach ([self::G_MESSAGE, self::G_FILE] as $name) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($arrStr, $name);
                $g->setInitializer($arrStr->constNull());
            }
        }
    }

    private static function global(Context $context, string $name): Value
    {
        self::ensureAotRingGlobals($context);
        $g = $context->module->getNamedGlobal($name);
        if (null === $g) {
            throw new \LogicException($name.' missing (#29161)');
        }

        return $g;
    }

    private static function implementExchangeBridge(Context $context): void
    {
        $abiName = '__compiler_libxml_use_internal_errors';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i32, $i32)
            );

        $entry = $fn->appendBasicBlock('libxml_use_internal_errors_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $hasNew = $context->builder->icmp(
            Builder::INT_NE,
            $fn->getParam(0),
            $i32->constInt(0, false)
        );
        $newValue = $context->builder->icmp(
            Builder::INT_NE,
            $fn->getParam(1),
            $i32->constInt(0, false)
        );
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::EXCHANGE_HELPER),
            [$hasNew, $newValue]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementClearBridge(Context $context): void
    {
        $abiName = '__compiler_libxml_clear_errors';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($voidTy, false));

        $entry = $fn->appendBasicBlock('libxml_clear_errors_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, self::CLEAR_HELPER));
        if (self::isStandalone($context)) {
            $context->builder->store(
                $context->getTypeFromString('int64')->constInt(0, true),
                self::global($context, self::G_COUNT)
            );
        }
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetErrorsBridge(Context $context): void
    {
        $abiName = '__compiler_libxml_get_errors';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false)
            );

        $entry = $fn->appendBasicBlock('libxml_get_errors_bridge_entry');
        $context->builder->positionAtEnd($entry);
        if (self::isStandalone($context)) {
            // Dedicated ABI fn (hrtime_pair peer): fresh LibXMLError[] from ring globals.
            $context->builder->returnValue(self::emitBuildErrorsHtFromRing($context));
        } else {
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::GET_ERRORS_HELPER),
                []
            );
            $context->builder->returnValue(
                JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $htPtr)
            );
        }
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetLastErrorBridge(Context $context): void
    {
        $abiName = '__compiler_libxml_get_last_error';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($objPtr, false)
            );

        $entry = $fn->appendBasicBlock('libxml_get_last_error_bridge_entry');
        $context->builder->positionAtEnd($entry);
        if (self::isStandalone($context)) {
            $context->builder->returnValue(self::emitBuildLastErrorFromRing($context));
        } else {
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::GET_LAST_HELPER),
                []
            );
            $context->builder->returnValue(
                JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $objPtr)
            );
        }
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRecordBridge(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_libxml_record_error',
            'libxml_record_error_bridge_entry',
            [$i64, $i64, $i64, $strPtr, $strPtr, $i64],
            $context->getTypeFromString('void'),
            self::RECORD_SCALARS_HELPER,
            self::HELPER_PATH,
            self::SCALAR_HELPERS,
            '#29161'
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#29161');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::isStandalone($context) ? self::SCALAR_HELPERS : self::COMPILED_HELPERS,
            '#29161'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after LibxmlUseInternalErrorsRuntime bridge (#29161)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
