<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of error_get_last() runtime (issue #5534, #3158).
 *
 * Replaces lib/AOT/runtime/phpc_last_error.c. php-src: ext/standard/basic_functions.c
 */
final class LastErrorRuntimeLlvm
{
    private static int $blockSuffix = 0;

    private const G_ACTIVE = 'phpc_last_error_active';

    private const G_TYPE = 'phpc_last_error_type';

    private const G_LINE = 'phpc_last_error_line';

    private const G_MESSAGE = 'phpc_last_error_message';

    private const G_FILE = 'phpc_last_error_file';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::$blockSuffix = 0;
        $probe = $context->module->getNamedFunction('__phpc_last_error_is_active');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureLibcAlloc($context);
        self::ensureHashtableHelpers($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');

        $recordProbe = $context->module->getNamedFunction('__phpc_last_error_record');
        $ftRecord = $context->context->functionType($voidTy, false, $i32, $i8p, $sizeT, $i8p, $i32);
        $fnRecord = null !== $recordProbe
            ? $recordProbe
            : $context->module->addFunction('__phpc_last_error_record', $ftRecord);
        self::implementRecord($context, $fnRecord);

        $clearProbe = $context->module->getNamedFunction('__phpc_last_error_clear');
        $ftClear = $context->context->functionType($voidTy, false);
        $fnClear = null !== $clearProbe
            ? $clearProbe
            : $context->module->addFunction('__phpc_last_error_clear', $ftClear);
        self::implementClear($context, $fnClear);

        $activeProbe = $context->module->getNamedFunction('__phpc_last_error_is_active');
        $ftActive = $context->context->functionType($i32, false);
        $fnActive = null !== $activeProbe
            ? $activeProbe
            : $context->module->addFunction('__phpc_last_error_is_active', $ftActive);
        self::implementIsActive($context, $fnActive);
        $context->registerFunction('__phpc_last_error_is_active', $fnActive);

        $htProbe = $context->module->getNamedFunction('__phpc_last_error_to_hashtable');
        $ftHt = $context->context->functionType($htPtr, false);
        $fnHt = null !== $htProbe
            ? $htProbe
            : $context->module->addFunction('__phpc_last_error_to_hashtable', $ftHt);
        self::implementToHashtable($context, $fnHt, $fnActive);

        self::registerLinkedRuntime($context);
    }

    private static function implementRecord(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('ler_entry');
        $context->builder->positionAtEnd($entry);

        $type = $fn->getParam(0);
        $msg = $fn->getParam(1);
        $msgLen = $fn->getParam(2);
        $file = $fn->getParam(3);
        $line = $fn->getParam(4);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        self::freeStoredCstr($context, $fn, self::globalPtr($context, self::G_MESSAGE, $i8p));
        self::freeStoredCstr($context, $fn, self::globalPtr($context, self::G_FILE, $i8p));

        $newMsg = self::dupBuffer($context, $msg, $msgLen);
        $fileCstr = self::nullSafeCstr($context, $fn, $file);
        $fileLen = $context->builder->call($context->lookupFunction('strlen'), $fileCstr);
        $newFile = self::dupBuffer($context, $fileCstr, $fileLen);

        $context->builder->store($i32->constInt(1, false), self::globalPtr($context, self::G_ACTIVE, $i32));
        $context->builder->store($type, self::globalPtr($context, self::G_TYPE, $i32));
        $context->builder->store($line, self::globalPtr($context, self::G_LINE, $i32));
        $context->builder->store($newMsg, self::globalPtr($context, self::G_MESSAGE, $i8p));
        $context->builder->store($newFile, self::globalPtr($context, self::G_FILE, $i8p));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementClear(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('lec_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        self::freeStoredCstr($context, $fn, self::globalPtr($context, self::G_MESSAGE, $i8p));
        self::freeStoredCstr($context, $fn, self::globalPtr($context, self::G_FILE, $i8p));

        $context->builder->store($i32->constInt(0, false), self::globalPtr($context, self::G_ACTIVE, $i32));
        $context->builder->store($i32->constInt(0, false), self::globalPtr($context, self::G_TYPE, $i32));
        $context->builder->store($i32->constInt(0, false), self::globalPtr($context, self::G_LINE, $i32));
        $context->builder->store($i8p->constNull(), self::globalPtr($context, self::G_MESSAGE, $i8p));
        $context->builder->store($i8p->constNull(), self::globalPtr($context, self::G_FILE, $i8p));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementIsActive(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('lea_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        $active = $context->builder->load(self::globalPtr($context, self::G_ACTIVE, $i32));
        $msg = $context->builder->load(self::globalPtr($context, self::G_MESSAGE, $i8p));
        $activeNonZero = $context->builder->icmp(Builder::INT_NE, $active, $i32->constInt(0, false));
        $msgNonNull = $context->builder->icmp(Builder::INT_NE, $msg, $i8p->constNull());
        $ok = $context->builder->and($activeNonZero, $msgNonNull);
        $context->builder->returnValue($context->builder->zext($ok, $i32));
        $context->builder->clearInsertionPosition();
    }

    private static function implementToHashtable(Context $context, Value $fn, Value $fnActive): void
    {
        $entry = $fn->appendBasicBlock('leh_entry');
        $emptyBb = $fn->appendBasicBlock('leh_empty');
        $workBb = $fn->appendBasicBlock('leh_work');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');

        $active = $context->builder->call($fnActive);
        $hasError = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->trunc($active, $i32),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($hasError, $workBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->builder->positionAtEnd($workBb);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $errType = $context->builder->load(self::globalPtr($context, self::G_TYPE, $i32));
        $errLine = $context->builder->load(self::globalPtr($context, self::G_LINE, $i32));
        $msgCstr = $context->builder->load(self::globalPtr($context, self::G_MESSAGE, $i8p));
        $fileCstr = $context->builder->load(self::globalPtr($context, self::G_FILE, $i8p));

        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgCstr);
        $fileLen = $context->builder->call($context->lookupFunction('strlen'), $fileCstr);

        $msgStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($msgLen, $i64),
            $msgCstr
        );
        $fileStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($fileLen, $i64),
            $fileCstr
        );

        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        $keyType = self::literalKeyString($context, 'type');
        $keyMessage = self::literalKeyString($context, 'message');
        $keyFile = self::literalKeyString($context, 'file');
        $keyLine = self::literalKeyString($context, 'line');

        $context->builder->call(
            $setLong,
            $ht,
            $keyType,
            $context->builder->sext($errType, $i64)
        );
        $context->builder->call($setString, $ht, $keyMessage, $msgStr);
        $context->builder->call($setString, $ht, $keyFile, $fileStr);
        $context->builder->call(
            $setLong,
            $ht,
            $keyLine,
            $context->builder->sext($errLine, $i64)
        );

        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function dupBuffer(Context $context, Value $src, Value $len): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $voidPtr = $context->getTypeFromString('void*');
        $allocSize = $context->builder->add($len, $sizeT->constInt(1, false));
        $raw = $context->builder->call($context->lookupFunction('malloc'), $allocSize);
        $out = $context->builder->pointerCast($raw, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($src),
            $len
        );
        $end = $context->builder->gep($out, $len);
        $context->builder->store($i8->constInt(0, false), $end);

        return $out;
    }

    private static function freeStoredCstr(Context $context, Value $fn, Value $slot): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $cur = $context->builder->load($slot);
        $nonNull = $context->builder->icmp(Builder::INT_NE, $cur, $i8p->constNull());
        $suffix = (string) ++self::$blockSuffix;
        $freeBb = $fn->appendBasicBlock('le_free_'.$suffix);
        $skipBb = $fn->appendBasicBlock('le_skip_'.$suffix);
        $contBb = $fn->appendBasicBlock('le_cont_'.$suffix);
        $context->builder->branchIf($nonNull, $freeBb, $skipBb);
        $context->builder->positionAtEnd($freeBb);
        $context->builder->call($context->lookupFunction('free'), $cur);
        $context->builder->store($i8p->constNull(), $slot);
        $context->builder->branch($contBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($contBb);
        $context->builder->positionAtEnd($contBb);
    }

    private static function nullSafeCstr(Context $context, Value $fn, Value $ptr): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $null = $i8p->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $null);
        $suffix = (string) ++self::$blockSuffix;
        $emptyBb = $fn->appendBasicBlock('le_empty_cstr_'.$suffix);
        $useBb = $fn->appendBasicBlock('le_use_cstr_'.$suffix);
        $doneBb = $fn->appendBasicBlock('le_cstr_done_'.$suffix);
        $context->builder->branchIf($isNull, $emptyBb, $useBb);
        $context->builder->positionAtEnd($emptyBb);
        $emptyGlobal = $context->module->getNamedGlobal('phpc_last_error_empty_cstr');
        if (null === $emptyGlobal) {
            $emptyGlobal = $context->module->addGlobal($context->getTypeFromString('int8'), 'phpc_last_error_empty_cstr');
            $emptyGlobal->setInitializer($context->getTypeFromString('int8')->constInt(0, false));
        }
        $emptyPtr = $context->builder->pointerCast($emptyGlobal, $i8p);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($useBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i8p);
        $phi->addIncoming($emptyPtr, $emptyBb);
        $phi->addIncoming($ptr, $useBb);

        return $phi;
    }

    private static function literalKeyString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('LastErrorRuntime global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::G_ACTIVE)) {
            $g = $context->module->addGlobal($i32, self::G_ACTIVE);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_TYPE)) {
            $g = $context->module->addGlobal($i32, self::G_TYPE);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_LINE)) {
            $g = $context->module->addGlobal($i32, self::G_LINE);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_MESSAGE)) {
            $g = $context->module->addGlobal($i8p, self::G_MESSAGE);
            $g->setInitializer($i8p->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_FILE)) {
            $g = $context->module->addGlobal($i8p, self::G_FILE);
            $g->setInitializer($i8p->constNull());
        }
    }

    private static function ensureLibcAlloc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            'malloc',
            $context->context->functionType($voidPtr, false, $sizeT)
        );
        $voidTy = $context->getTypeFromString('void');
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $i8p));
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
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
            $context->context->functionType($strPtr, false, $i64, $context->getTypeFromString('int8*'))
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
        foreach (
            [
                '__phpc_last_error_record',
                '__phpc_last_error_clear',
                '__phpc_last_error_is_active',
                '__phpc_last_error_to_hashtable',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after LastErrorRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
