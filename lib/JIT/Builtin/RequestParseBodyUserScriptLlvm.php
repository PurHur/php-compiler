<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script standalone AOT: init-safe LLVM for request_parse_body() (#5965, #17316).
 *
 * Nested {@see RequestParseBodyJitHelper} / {@see RequestParseBodyNativeJitHelper} JIT during
 * user-script link segfaults; this hand-lowering reads putenv overlay + libc REQUEST_BODY and
 * parses urlencoded bodies via {@see ParseStrUserScriptDelimitedJit}.
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

    private static function ensureGetenvSubhelper(Context $context): void
    {
        $name = '__phpc_rpb_overlay_getenv';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        LibcExtern::register($context);
        $i8p = $context->getTypeFromString('int8*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p)
            );

        $null = $i8p->constNull();
        $entry = $fn->appendBasicBlock('rpb_getenv_entry');
        $context->builder->positionAtEnd($entry);
        $nameCstr = $fn->getParam(0);
        $nameNull = $context->builder->icmp(Builder::INT_EQ, $nameCstr, $null);
        $miss = $fn->appendBasicBlock('rpb_getenv_miss');
        $lookup = $fn->appendBasicBlock('rpb_getenv_lookup');
        $context->builder->branchIf($nameNull, $miss, $lookup);

        $context->builder->positionAtEnd($lookup);
        $overlay = $context->builder->call(
            $context->lookupFunction('__compiler_env_local_lookup'),
            $nameCstr
        );
        $overlayHit = $context->builder->icmp(Builder::INT_NE, $overlay, $null);
        $hit = $fn->appendBasicBlock('rpb_getenv_hit');
        $libc = $fn->appendBasicBlock('rpb_getenv_libc');
        $context->builder->branchIf($overlayHit, $hit, $libc);

        $context->builder->positionAtEnd($hit);
        $context->builder->returnValue($overlay);

        $context->builder->positionAtEnd($libc);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('getenv'), $nameCstr)
        );

        $context->builder->positionAtEnd($miss);
        $context->builder->returnValue($null);

        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
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
