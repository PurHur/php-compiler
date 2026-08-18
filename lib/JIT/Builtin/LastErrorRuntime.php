<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_last_error_* via ErrorLastJitHelper PHP (#9454, #9607, #25318).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ExecutionLimits #25269).
 * Thin LLVM bridges forward the __phpc_last_error_* ABI. php-src: ext/standard/basic_functions.c
 */
final class LastErrorRuntime
{
    private const HELPER_PATH = '/ext/standard/ErrorLastJitHelper.php';

    private const RECORD_HELPER = 'PHPCompiler\\ext\\standard\\ErrorLastJitHelper::record';

    private const CLEAR_HELPER = 'PHPCompiler\\ext\\standard\\ErrorLastJitHelper::clear';

    private const ACTIVE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorLastJitHelper::isActive';

    private const TYPE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorLastJitHelper::getType';

    private const MESSAGE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorLastJitHelper::getMessage';

    private const FILE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorLastJitHelper::getFile';

    private const LINE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorLastJitHelper::getLine';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RECORD_HELPER,
        self::CLEAR_HELPER,
        self::ACTIVE_HELPER,
        self::TYPE_HELPER,
        self::MESSAGE_HELPER,
        self::FILE_HELPER,
        self::LINE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_last_error_record',
        '__phpc_last_error_clear',
        '__phpc_last_error_is_active',
        '__phpc_last_error_to_hashtable',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_last_error_is_active');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::ensureHashtableHelpers($context);
        self::implementRecordBridge($context);
        self::implementClearBridge($context);
        self::implementActiveBridge($context);
        self::implementHashtableBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRecordBridge(Context $context): void
    {
        $abiName = '__phpc_last_error_record';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i32, $i8p, $sizeT, $i8p, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ler_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $type = $fn->getParam(0);
        $msg = $fn->getParam(1);
        $msgLen = $fn->getParam(2);
        $file = $fn->getParam(3);
        $line = $fn->getParam(4);

        $msgStr = self::cstrToStringWithLength($context, $msg, $context->builder->zExt($msgLen, $i64));
        $fileStr = self::nullSafeCstrToString($context, $fn, $file);
        $context->builder->call(
            self::helperFunction($context, self::RECORD_HELPER),
            $context->builder->sext($type, $i64),
            $msgStr,
            $fileStr,
            $context->builder->sext($line, $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClearBridge(Context $context): void
    {
        self::implementVoidBridge($context, '__phpc_last_error_clear', self::CLEAR_HELPER);
    }

    private static function implementActiveBridge(Context $context): void
    {
        $abiName = '__phpc_last_error_is_active';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('lea_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $active = $context->builder->call(self::helperFunction($context, self::ACTIVE_HELPER));
        $context->builder->returnValue($context->builder->zext($active, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementHashtableBridge(Context $context): void
    {
        $abiName = '__phpc_last_error_to_hashtable';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($htPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('leh_bridge_entry');
        $emptyBb = $fn->appendBasicBlock('leh_bridge_empty');
        $workBb = $fn->appendBasicBlock('leh_bridge_work');
        $context->builder->positionAtEnd($entry);

        $active = $context->builder->call(self::helperFunction($context, self::ACTIVE_HELPER));
        $hasError = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zext($active, $i32),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($hasError, $workBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($workBb);

        $i64 = $context->getTypeFromString('int64');
        $errType = $context->builder->call(self::helperFunction($context, self::TYPE_HELPER));
        $errLine = $context->builder->call(self::helperFunction($context, self::LINE_HELPER));
        $msgStr = $context->builder->call(self::helperFunction($context, self::MESSAGE_HELPER));
        $fileStr = $context->builder->call(self::helperFunction($context, self::FILE_HELPER));

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');

        $context->builder->call(
            $setLong,
            $ht,
            self::literalKeyString($context, 'type'),
            $context->builder->sext($errType, $i64)
        );
        $context->builder->call($setString, $ht, self::literalKeyString($context, 'message'), $msgStr);
        $context->builder->call($setString, $ht, self::literalKeyString($context, 'file'), $fileStr);
        $context->builder->call(
            $setLong,
            $ht,
            self::literalKeyString($context, 'line'),
            $context->builder->sext($errLine, $i64)
        );
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementVoidBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('le_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function nullSafeCstrToString(Context $context, LlvmFunction $fn, Value $ptr): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $null = $i8p->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $null);
        $emptyBb = $fn->appendBasicBlock('le_file_empty');
        $useBb = $fn->appendBasicBlock('le_file_use');
        $doneBb = $fn->appendBasicBlock('le_file_done');
        $context->builder->branchIf($isNull, $emptyBb, $useBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = self::literalEmptyString($context);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($useBb);
        $fileStr = self::cstrToString($context, $ptr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($emptyStr, $emptyBb);
        $phi->addIncoming($fileStr, $useBb);

        return $phi;
    }

    private static function literalEmptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $charPtr)
        );
    }

    private static function literalKeyString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $context->builder->pointerCast($context->constantFromString($text), $charPtr)
        );
    }

    private static function cstrToStringWithLength(Context $context, Value $cstr, Value $lenI64): Value
    {
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25318');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25318'
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        self::ensureExternal(
            $context,
            '__hashtable__alloc',
            $context->context->functionType($htPtr, false)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyLong',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $strPtr, $i64)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyString',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $strPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $context->getTypeFromString('char*'))
        );
        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($context->getTypeFromString('size_t'), false, $context->getTypeFromString('int8*'))
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after LastErrorRuntime bridge (#9454)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
