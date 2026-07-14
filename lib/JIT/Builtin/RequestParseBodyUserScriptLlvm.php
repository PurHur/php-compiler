<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script standalone AOT: init-safe LLVM for request_parse_body() (#5965, #17316).
 *
 * Linked during user-script lowering (not standalone init) so GetenvJitHelper shares
 * putenv() overlay state. Avoids init-linked EnvLocalJitHelper lookup segfault.
 * php-src: ext/standard/http.c
 */
final class RequestParseBodyUserScriptLlvm
{
    public const BRIDGE_NAME = '__compiler_request_parse_body_user_aot';

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::BRIDGE_NAME);
        if (null !== $probe && self::bridgeBodyComplete($probe)) {
            $context->registerFunction(self::BRIDGE_NAME, $probe);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        LibcExtern::register($context);
        ParseStrRuntime::ensureUserScriptLinked($context);
        self::ensureGetenvSubhelper($context);
        self::emitBridge($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    private static function emitBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::BRIDGE_NAME);
        if (null !== $probe && self::bridgeBodyComplete($probe)) {
            $context->registerFunction(self::BRIDGE_NAME, $probe);

            return;
        }

        $fn = null !== $probe ? $probe : self::declareBridge($context);
        if ($fn->countBasicBlocks() > 0) {
            foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $entry = $fn->appendBasicBlock('rpb_user_entry');
        $context->builder->positionAtEnd($entry);

        $bodySlot = self::entryAlloca($context, $entry, $context->getTypeFromString('int8*'));
        $bodyVal = $context->builder->call(
            $context->lookupFunction('__phpc_rpb_overlay_getenv'),
            $context->pointerFromStringConstant('REQUEST_BODY')
        );
        $context->builder->store($bodyVal, $bodySlot);

        $post = $fn->getParam(0);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullPost = $context->builder->icmp(Builder::INT_EQ, $post, $htPtr->constNull());
        $early = $fn->appendBasicBlock('rpb_user_early');
        $work = $fn->appendBasicBlock('rpb_user_work');
        $context->builder->branchIf($nullPost, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $bodyEmpty = self::isCstrSlotEmpty($context, $bodySlot);
        $done = $fn->appendBasicBlock('rpb_user_done');
        $parse = $fn->appendBasicBlock('rpb_user_parse');
        $context->builder->branchIf($bodyEmpty, $done, $parse);

        $context->builder->positionAtEnd($parse);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_parse_delimited_pairs'),
            $post,
            $context->builder->load($bodySlot),
            $i8->constInt(38, false),
            $i32->constInt(0, false)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();

        $context->registerFunction(self::BRIDGE_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareBridge(Context $context): LlvmFunction
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $void = $context->getTypeFromString('void');

        return $context->module->addFunction(
            self::BRIDGE_NAME,
            $context->context->functionType($void, false, $htPtr, $htPtr)
        );
    }

    /** Read putenv overlay via GetenvJitHelper (same compile unit as putenv lowering, #17316). */
    private static function ensureGetenvSubhelper(Context $context): void
    {
        $name = '__phpc_rpb_overlay_getenv';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        StringGetenv::ensureJitHelperCompiled($context);
        self::ensureGetenvSubhelperExternals($context);

        $i8p = $context->getTypeFromString('int8*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p)
            );

        $null = $i8p->constNull();
        $empty = $context->pointerFromStringConstant('');
        $entry = $fn->appendBasicBlock('rpb_getenv_entry');
        $context->builder->positionAtEnd($entry);
        $nameCstr = $fn->getParam(0);
        $nameNull = $context->builder->icmp(Builder::INT_EQ, $nameCstr, $null);
        $miss = $fn->appendBasicBlock('rpb_getenv_miss');
        $body = $fn->appendBasicBlock('rpb_getenv_body');
        $context->builder->branchIf($nameNull, $miss, $body);

        $context->builder->positionAtEnd($body);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $nameLen = $context->builder->call($context->lookupFunction('strlen'), $nameCstr);
        $nameLenI64 = $nameLen->typeOf() === $i64
            ? $nameLen
            : $context->builder->zExt($nameLen, $i64);
        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $nameLenI64,
            $nameCstr
        );
        $overlayRaw = JitNestedHelperCoerce::callHelper(
            $context,
            StringGetenv::helperFunction(
                $context,
                'PHPCompiler\\ext\\standard\\GetenvJitHelper::getenv'
            ),
            [$nameStr, $i8->constInt(0, false)]
        );
        $isMiss = JitNestedHelperCoerce::isHelperResultNull($context, $overlayRaw);
        $hit = $fn->appendBasicBlock('rpb_getenv_hit');
        $libc = $fn->appendBasicBlock('rpb_getenv_libc');
        $context->builder->branchIf($isMiss, $libc, $hit);

        $context->builder->positionAtEnd($hit);
        $overlayPtr = JitNestedHelperCoerce::valueBoxPtrFromHelperResult($context, $overlayRaw);
        $overlayType = $context->builder->load(
            $context->builder->structGep($overlayPtr, $context->structFieldMap['__value__']['type'])
        );
        $isFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $overlayType,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $falseBb = $fn->appendBasicBlock('rpb_getenv_false');
        $stringBb = $fn->appendBasicBlock('rpb_getenv_string');
        $context->builder->branchIf($isFalse, $falseBb, $stringBb);

        $context->builder->positionAtEnd($stringBb);
        $valueStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $overlayPtr
        );
        $context->builder->returnValue(self::dupCstrFromStringStruct($context, $valueStr));

        $context->builder->positionAtEnd($falseBb);
        $context->builder->branch($libc);

        $context->builder->positionAtEnd($libc);
        $env = $context->builder->call($context->lookupFunction('getenv'), $nameCstr);
        $envNull = $context->builder->icmp(Builder::INT_EQ, $env, $null);
        $emptyBb = $fn->appendBasicBlock('rpb_getenv_empty');
        $dupBb = $fn->appendBasicBlock('rpb_getenv_dup');
        $context->builder->branchIf($envNull, $emptyBb, $dupBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($empty);

        $context->builder->positionAtEnd($dupBb);
        $context->builder->returnValue(self::dupCstrBytes($context, $env));

        $context->builder->positionAtEnd($miss);
        $context->builder->returnValue($null);

        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureGetenvSubhelperExternals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach ([
            ['strlen', $i64, [$i8p]],
            ['malloc', $voidPtr, [$sizeT]],
            ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['__value__readString', $strPtr, [$valuePtr]],
        ] as [$sym, $ret, $params]) {
            if (null === $context->module->getNamedFunction($sym)) {
                $context->module->addFunction(
                    $sym,
                    $context->context->functionType($ret, false, ...$params)
                );
            }
        }
        LibcExtern::register($context);
    }

    private static function dupCstrFromStringStruct(Context $context, Value $src): Value
    {
        $strMap = $context->structFieldMap['__string__'];
        $valueBytes = $context->builder->structGep($src, $strMap['value']);

        return self::dupCstrBytes($context, $valueBytes);
    }

    private static function dupCstrBytes(Context $context, Value $src): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->call($context->lookupFunction('strlen'), $src);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($len, $sizeT->constInt(1, false))
        );
        $dest = $context->builder->pointerCast($buf, $i8p);
        $context->builder->call($context->lookupFunction('memcpy'), $dest, $src, $len);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($dest, $len)
        );

        return $dest;
    }

    private static function entryAlloca(Context $context, \PHPLLVM\BasicBlock $entry, $type): Value
    {
        $saved = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($entry);
        $slot = $context->builder->alloca($type, 1, 'rpb_slot');
        $context->builder->positionAtEnd($saved);

        return $slot;
    }

    private static function isCstrSlotEmpty(Context $context, Value $slot): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $cstr = $context->builder->load($slot);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $cstr,
            $context->getTypeFromString('int8*')->constNull()
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($cstr), $i8->constInt(0, false));

        return $context->builder->or($isNull, $isEmpty);
    }

    private static function bridgeBodyComplete(LlvmFunction $fn): bool
    {
        foreach ($fn->getBasicBlocks() as $block) {
            if ('rpb_user_work' === $block->getName() && null !== $block->getTerminator()) {
                return true;
            }
        }

        return false;
    }
}
