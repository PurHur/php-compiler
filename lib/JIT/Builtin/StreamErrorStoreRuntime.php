<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_last_errors() / stream_clear_errors() (#21020).
 *
 * Thin standalone AOT keeps the error in module globals (NestedJIT statics / HashTable returns
 * are not shared or ABI-safe under thin init — peer LibxmlUseInternalErrorsRuntime #29161).
 *
 * php-src: ext/standard/streamsfuncs.c
 */
final class StreamErrorStoreRuntime
{
    public const FN_CLEAR = '__compiler_stream_error_clear';

    public const FN_LAST = '__compiler_stream_last_errors';

    public const FN_RECORD_OPEN = '__compiler_stream_error_record_open_failed';

    private const HELPER_PATH = '/ext/standard/StreamErrorStoreJitHelper.php';

    private const CLEAR_HELPER = 'PHPCompiler\\ext\\standard\\StreamErrorStoreJitHelper::clear';

    private const GET_ERRORS_HT_HELPER = 'PHPCompiler\\ext\\standard\\StreamErrorStoreJitHelper::getErrorsHt';

    private const RECORD_HELPER = 'PHPCompiler\\ext\\standard\\StreamErrorStoreJitHelper::recordOpenFailed';

    private const G_ACTIVE = '__compiler_stream_err_active';

    private const G_MESSAGE = '__compiler_stream_err_message';

    private const G_WRAPPER = '__compiler_stream_err_wrapper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CLEAR_HELPER,
        self::GET_ERRORS_HT_HELPER,
        self::RECORD_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function recordOpenFailed(Context $context, Value $pathStr, Value $detailStr): void
    {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::FN_RECORD_OPEN),
            $pathStr,
            $detailStr
        );
    }

    public static function implement(Context $context): void
    {
        $lastProbe = $context->module->getNamedFunction(self::FN_LAST);
        if (null !== $lastProbe && $lastProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        self::ensureJitHelperCompiled($context);
        if (self::isStandalone($context)) {
            self::ensureAotGlobals($context);
        }
        self::implementClearBridge($context);
        self::implementLastBridge($context);
        self::implementRecordBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function isStandalone(Context $context): bool
    {
        return Builtin::LOAD_TYPE_STANDALONE === $context->loadType;
    }

    private static function implementClearBridge(Context $context): void
    {
        $abiName = self::FN_CLEAR;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $void = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($void, false));

        $entry = $fn->appendBasicBlock('stream_error_clear_entry');
        $context->builder->positionAtEnd($entry);
        if (self::isStandalone($context)) {
            self::emitAotClear($context);
        } else {
            JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::CLEAR_HELPER),
                []
            );
        }
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementLastBridge(Context $context): void
    {
        $abiName = self::FN_LAST;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($htPtr, false));
        // Mid-main ensureLinked: scope to bridge fn so BasicBlockHelper::append lands here (#21020).
        $context->registerFunction($abiName, $fn);
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use ($context, $fn, $htPtr): void {
            $entry = $fn->appendBasicBlock('stream_err_last_entry');
            $context->builder->positionAtEnd($entry);
            if (self::isStandalone($context)) {
                $context->builder->returnValue(self::emitBuildErrorsHtFromGlobals($context));
            } else {
                $raw = JitNestedHelperCoerce::callHelper(
                    $context,
                    self::helperFunction($context, self::GET_ERRORS_HT_HELPER),
                    []
                );
                $context->builder->returnValue(
                    JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $htPtr)
                );
            }
        });
        $context->builder->clearInsertionPosition();
    }

    private static function implementRecordBridge(Context $context): void
    {
        $abiName = self::FN_RECORD_OPEN;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $void = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($void, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('stream_error_record_entry');
        $context->builder->positionAtEnd($entry);
        if (self::isStandalone($context)) {
            self::emitAotRecordOpen($context, $fn->getParam(0), $fn->getParam(1));
        } else {
            JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::RECORD_HELPER),
                [$fn->getParam(0), $fn->getParam(1)]
            );
        }
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitAotClear(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store($i64->constInt(0, true), self::global($context, self::G_ACTIVE));
    }

    private static function emitAotRecordOpen(Context $context, Value $pathStr, Value $detailStr): void
    {
        $i64 = $context->getTypeFromString('int64');
        $msg = self::buildOpenFailedMessage($context, $detailStr);
        $wrapper = self::buildWrapperName($context, $pathStr);
        $context->builder->store($msg, self::global($context, self::G_MESSAGE));
        $context->builder->store($wrapper, self::global($context, self::G_WRAPPER));
        $context->builder->store($i64->constInt(1, true), self::global($context, self::G_ACTIVE));
    }

    private static function emitBuildErrorsHtFromGlobals(Context $context): Value
    {
        self::ensureStreamErrorClass($context);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $active = $context->builder->load(self::global($context, self::G_ACTIVE));
        $empty = $context->builder->icmp(Builder::INT_EQ, $active, $i64->constInt(0, true));
        $takeBb = BasicBlockHelper::append($context, 'stream_err_last_take');
        $doneBb = BasicBlockHelper::append($context, 'stream_err_last_done');
        $context->builder->branchIf($empty, $doneBb, $takeBb);

        $context->builder->positionAtEnd($takeBb);
        $obj = self::materializeStreamErrorFromGlobals($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectAt'),
            $ht,
            $sizeT->constInt(0, false),
            $obj
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ht;
    }

    private static function materializeStreamErrorFromGlobals(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = self::ensureStreamErrorClass($context);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $className = 'StreamError';

        $message = $context->builder->load(self::global($context, self::G_MESSAGE));
        $wrapper = $context->builder->load(self::global($context, self::G_WRAPPER));
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $codeCase = $objectType->jitEnumCaseFromBacking(
            $objectType->lookup('StreamErrorCode'),
            'OpenFailed'
        );

        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, 'code'),
            $codeCase,
            JITVariable::TYPE_VALUE
        );

        foreach (['message' => $message, 'wrapperName' => $wrapper] as $prop => $str) {
            $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
            $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, $className, $prop),
                $propVar,
                JITVariable::TYPE_VALUE
            );
        }

        $severity = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $i64->constInt(2, true)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, 'severity'),
            $severity,
            JITVariable::TYPE_VALUE
        );
        $terminating = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::KIND_VALUE,
            $i1->constInt(1, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, 'terminating'),
            $terminating,
            JITVariable::TYPE_VALUE
        );
        $nullVar = new JITVariable($context, JITVariable::TYPE_NULL, JITVariable::KIND_VALUE, $nullStr);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, 'param'),
            $nullVar,
            JITVariable::TYPE_VALUE
        );

        return $obj;
    }

    private static function ensureStreamErrorClass(Context $context): int
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('StreamError');
        foreach (['code' => JITVariable::TYPE_VALUE, 'message' => JITVariable::TYPE_VALUE, 'wrapperName' => JITVariable::TYPE_VALUE, 'severity' => JITVariable::TYPE_VALUE, 'terminating' => JITVariable::TYPE_VALUE, 'param' => JITVariable::TYPE_VALUE] as $prop => $ty) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, $ty);
            }
        }

        return $classId;
    }

    private static function buildOpenFailedMessage(Context $context, Value $detailStr): Value
    {
        StringTriggerError::ensureLinked($context);
        LibcExtern::ensureSnprintf($context);
        $map = $context->structFieldMap['__string__'];
        $detailPtr = $context->builder->structGep($detailStr, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $bufSize = $sizeT->constInt(512, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('Failed to open stream: %s'),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $detailPtr
        );
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zext($written, $i64),
            $bufChar
        );
    }

    private static function buildWrapperName(Context $context, Value $pathStr): Value
    {
        // php-src plainfile default for path without scheme (#21020 repro).
        $plain = $context->builder->pointerCast(
            $context->constantFromString('plainfile'),
            $context->getTypeFromString('char*')
        );

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->getTypeFromString('int64')->constInt(9, false),
            $plain
        );
    }

    private static function ensureAotGlobals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === $context->module->getNamedGlobal(self::G_ACTIVE)) {
            $g = $context->module->addGlobal($i64, self::G_ACTIVE);
            $g->setInitializer($i64->constInt(0, true));
        }
        foreach ([self::G_MESSAGE, self::G_WRAPPER] as $name) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($strPtr, $name);
                $g->setInitializer($strPtr->constNull());
            }
        }
    }

    private static function global(Context $context, string $name): Value
    {
        $g = $context->module->getNamedGlobal($name);
        if (null === $g) {
            throw new \LogicException($name.' global missing in StreamErrorStoreRuntime (#21020)');
        }

        return $g;
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#21020');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21020'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::FN_CLEAR, self::FN_LAST, self::FN_RECORD_OPEN] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamErrorStoreRuntime bridge (#21020)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
