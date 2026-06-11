<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM default timezone state for date_default_timezone_get/set (#3292 phase 2).
 *
 * Mirrors ext/standard/VmDate::{defaultTimezoneGet,tryDefaultTimezoneSet}().
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_default_timezone_get/set).
 */
final class DefaultTimezoneRuntime
{
    private const G_TZ_PTR = 'phpc_default_timezone_ptr';

    private const G_TZ_LEN = 'phpc_default_timezone_len';

    private const ZONEINFO_PREFIX = '/usr/share/zoneinfo/';

    private const ACCESS_F_OK = 0;

    private const NOTICE_BUF = 256;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_default_timezone_get');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        StringTriggerError::ensureLinked($context);
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureValueWriters($context);

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');

        $getProbe = $context->module->getNamedFunction('__compiler_default_timezone_get');
        $ftGet = $context->context->functionType($voidTy, false, $valuePtr);
        $fnGet = null !== $getProbe
            ? $getProbe
            : $context->module->addFunction('__compiler_default_timezone_get', $ftGet);
        self::implementGet($context, $fnGet);

        $setProbe = $context->module->getNamedFunction('__compiler_default_timezone_set');
        $ftSet = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fnSet = null !== $setProbe
            ? $setProbe
            : $context->module->addFunction('__compiler_default_timezone_set', $ftSet);
        self::implementSet($context, $fnSet);

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGet(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('dtz_get_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $valuePtr = $context->getTypeFromString('__value__*');
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullRet = $fn->appendBasicBlock('dtz_get_null');
        $body = $fn->appendBasicBlock('dtz_get_body');
        $context->builder->branchIf($nullOut, $nullRet, $body);

        $context->builder->positionAtEnd($nullRet);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($body);
        $tzStr = self::loadCurrentTimezoneString($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $tzStr
        );
        $context->builder->returnVoid();
    }

    private static function implementSet(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('dtz_set_entry');
        $context->builder->positionAtEnd($entry);

        $tz = $fn->getParam(0);
        $out = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtr = $context->getTypeFromString('__value__*');
        $one = $i64->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);

        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullRet = $fn->appendBasicBlock('dtz_set_null');
        $validate = $fn->appendBasicBlock('dtz_set_validate');
        $context->builder->branchIf($nullOut, $nullRet, $validate);

        $context->builder->positionAtEnd($nullRet);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($validate);
        $tzLen = $context->builder->load($context->builder->structGep($tz, $strMap['length']));
        $tzBytes = $context->builder->structGep($tz, $strMap['value']);
        $tzCStr = self::stringToCstr($context, $tzBytes, $tzLen);

        $valid = self::timezoneIdIsValid($context, $fn, $tzCStr, $tzLen);
        $okBb = $fn->appendBasicBlock('dtz_set_ok');
        $failBb = $fn->appendBasicBlock('dtz_set_fail');
        $context->builder->branchIf($valid, $okBb, $failBb);

        $context->builder->positionAtEnd($okBb);
        self::storeCurrentTimezone($context, $tzCStr, $tzLen);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(1, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($failBb);
        self::emitInvalidTimezoneNotice($context, $fn, $tzCStr);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $zeroI32
        );
        $context->builder->returnVoid();
    }

    private static function loadCurrentTimezoneString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ptrGlobal = $context->module->getNamedGlobal(self::G_TZ_PTR);
        $lenGlobal = $context->module->getNamedGlobal(self::G_TZ_LEN);
        $tzPtr = $context->builder->load($ptrGlobal);
        $tzLen = $context->builder->load($lenGlobal);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $tzLen,
            $tzPtr
        );
    }

    private static function storeCurrentTimezone(Context $context, Value $tzCStr, Value $tzLen): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $one = $i64->constInt(1, false);
        $bufLen = $context->builder->add($tzLen, $one);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufLen);
        $context->intrinsic->memcpy($buf, $tzCStr, $tzLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($buf, $tzLen)
        );
        $ptrGlobal = $context->module->getNamedGlobal(self::G_TZ_PTR);
        $lenGlobal = $context->module->getNamedGlobal(self::G_TZ_LEN);
        $context->builder->store($buf, $ptrGlobal);
        $context->builder->store($tzLen, $lenGlobal);
    }

    private static function stringToCstr(Context $context, Value $bytes, Value $len): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $one = $i64->constInt(1, false);
        $bufLen = $context->builder->add($len, $one);
        $buf = $context->builder->alloca($i8, $bufLen, 'tz_cstr');
        $cstr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cstr, $bytes, $len, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($cstr, $len)
        );

        return $cstr;
    }

    private static function timezoneIdIsValid(
        Context $context,
        LlvmFunction $fn,
        Value $tzCStr,
        Value $tzLen
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $slash = $i32->constInt(ord('/'), false);

        $empty = $context->builder->icmp(Builder::INT_EQ, $tzLen, $zeroI64);
        $startsSlash = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->zExt($context->builder->load($tzCStr), $i32),
            $slash
        );
        $hasDotDot = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call(
                $context->lookupFunction('strstr'),
                $tzCStr,
                $context->builder->pointerCast($context->constantFromString('..'), $i8p)
            ),
            $i8p->constNull()
        );
        $quickBad = $context->builder->or(
            $context->builder->or($empty, $startsSlash),
            $hasDotDot
        );

        $quickFail = $fn->appendBasicBlock('dtz_valid_quick_fail');
        $pathBuild = $fn->appendBasicBlock('dtz_valid_path');
        $merge = $fn->appendBasicBlock('dtz_valid_merge');
        $context->builder->branchIf($quickBad, $quickFail, $pathBuild);

        $context->builder->positionAtEnd($quickFail);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($pathBuild);
        $prefix = self::ZONEINFO_PREFIX;
        $prefixLen = \strlen($prefix);
        $pathLen = $context->builder->add($tzLen, $i64->constInt($prefixLen, false));
        $pathBufLen = $context->builder->add($pathLen, $i64->constInt(1, false));
        $pathBuf = $context->builder->alloca($i8, $pathBufLen, 'zoneinfo_path');
        $pathCStr = $context->builder->pointerCast($pathBuf, $i8p);
        $prefixCStr = $context->builder->pointerCast($context->constantFromString($prefix), $i8p);
        $context->intrinsic->memcpy($pathCStr, $prefixCStr, $i64->constInt($prefixLen, false), false);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->gep($pathCStr, $i64->constInt($prefixLen, false)),
            $tzCStr,
            $tzLen
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->gep($pathCStr, $pathLen)
        );
        $accessRc = $context->builder->call(
            $context->lookupFunction('access'),
            $pathCStr,
            $i32->constInt(self::ACCESS_F_OK, false)
        );
        $exists = $context->builder->icmp(Builder::INT_EQ, $accessRc, $zeroI32);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($context->getTypeFromString('int1'), 'dtz_valid');
        $falseI1 = $context->getTypeFromString('int1')->constInt(0, false);
        $phi->addIncoming($falseI1, $quickFail);
        $phi->addIncoming($exists, $pathBuild);

        return $phi;
    }

    private static function emitInvalidTimezoneNotice(
        Context $context,
        LlvmFunction $fn,
        Value $tzCStr
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $prefix = 'date_default_timezone_set(): Timezone ID \'';
        $suffix = '\' is invalid';
        $buf = $context->builder->alloca(
            $context->getTypeFromString('int8'),
            self::NOTICE_BUF,
            'dtz_notice'
        );
        $bufCStr = $context->builder->pointerCast($buf, $i8p);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufCStr,
            $sizeT->constInt(self::NOTICE_BUF, false),
            $context->builder->pointerCast($context->constantFromString($prefix.'%s'.$suffix), $i8p),
            $tzCStr
        );
        $msgLen = $context->builder->zExt($written, $sizeT);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $bufCStr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_NOTICE, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function ensureGlobals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::G_TZ_LEN)) {
            $len = $context->module->addGlobal($i64, self::G_TZ_LEN);
            $len->setInitializer($i64->constInt(3, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_TZ_PTR)) {
            $ptr = $context->module->addGlobal($i8p, self::G_TZ_PTR);
            $ptr->setInitializer($context->pointerFromStringConstant('UTC'));
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $declarations = [
            ['malloc', $context->getTypeFromString('int8*'), [$context->getTypeFromString('int64')]],
            ['memcpy', $context->getTypeFromString('void'), [
                $context->getTypeFromString('int8*'),
                $context->getTypeFromString('int8*'),
                $context->getTypeFromString('int64'),
            ]],
            ['access', $context->getTypeFromString('int32'), [
                $context->getTypeFromString('int8*'),
                $context->getTypeFromString('int32'),
            ]],
            ['strstr', $context->getTypeFromString('int8*'), [
                $context->getTypeFromString('int8*'),
                $context->getTypeFromString('int8*'),
            ]],
            ['snprintf', $context->getTypeFromString('int32'), [
                $context->getTypeFromString('int8*'),
                $context->getTypeFromString('size_t'),
                $context->getTypeFromString('int8*'),
            ], true],
        ];

        foreach ($declarations as $spec) {
            $name = $spec[0];
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $ret = $spec[1];
            $params = $spec[2];
            $varArgs = $spec[3] ?? false;
            $ft = $context->context->functionType($ret, $varArgs, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function ensureValueWriters(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach (
            [
                ['__value__writeBool', [$valuePtr, $i32]],
                ['__value__writeString', [$valuePtr, $strPtr]],
            ] as [$name, $params]
        ) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $ft = $context->context->functionType($voidTy, false, ...$params);
            $context->module->addFunction($name, $ft);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_default_timezone_get', '__compiler_default_timezone_set'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }
}
