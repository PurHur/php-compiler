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
 * Reads REQUEST_BODY via libc getenv (mirrored from putenv() via POSIX setenv in JitEnv —
 * not putenv(malloc), which heap-corrupted under ≥2 mirrors #17316). strdup's the body
 * before strtok/urldecode in-place parse so environ is not mutated.
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

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $bodySlot = self::entryAlloca($context, $entry, $i8p);
        // Owned copy: parse_delimited_pairs mutates via strtok_r / urldecode in-place.
        // Feeding libc getenv's environ buffer directly corrupts setenv mirrors (#17316).
        $ownedSlot = self::entryAlloca($context, $entry, $i8);
        $context->builder->store($i8->constInt(0, false), $ownedSlot);

        $envRaw = $context->builder->call(
            $context->lookupFunction('getenv'),
            $context->pointerFromStringConstant('REQUEST_BODY')
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $envRaw, $i8p->constNull());
        $dupBb = $fn->appendBasicBlock('rpb_user_dup');
        $emptyBb = $fn->appendBasicBlock('rpb_user_empty');
        $afterBody = $fn->appendBasicBlock('rpb_user_after_body');
        $context->builder->branchIf($isNull, $emptyBb, $dupBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->store($context->pointerFromStringConstant(''), $bodySlot);
        $context->builder->branch($afterBody);

        $context->builder->positionAtEnd($dupBb);
        $dup = $context->builder->call($context->lookupFunction('strdup'), $envRaw);
        $context->builder->store($dup, $bodySlot);
        $context->builder->store($i8->constInt(1, false), $ownedSlot);
        $context->builder->branch($afterBody);

        $context->builder->positionAtEnd($afterBody);
        $post = $fn->getParam(0);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullPost = $context->builder->icmp(Builder::INT_EQ, $post, $htPtr->constNull());
        $early = $fn->appendBasicBlock('rpb_user_early');
        $work = $fn->appendBasicBlock('rpb_user_work');
        $context->builder->branchIf($nullPost, $early, $work);

        $context->builder->positionAtEnd($early);
        self::emitFreeOwnedBody($context, $bodySlot, $ownedSlot);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $bodyEmpty = self::isCstrSlotEmpty($context, $bodySlot);
        $done = $fn->appendBasicBlock('rpb_user_done');
        $parse = $fn->appendBasicBlock('rpb_user_parse');
        $context->builder->branchIf($bodyEmpty, $done, $parse);

        $context->builder->positionAtEnd($parse);
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
        self::emitFreeOwnedBody($context, $bodySlot, $ownedSlot);
        $context->builder->returnVoid();

        $context->registerFunction(self::BRIDGE_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitFreeOwnedBody(Context $context, Value $bodySlot, Value $ownedSlot): void
    {
        $i8 = $context->getTypeFromString('int8');
        $owned = $context->builder->load($ownedSlot);
        $isOwned = $context->builder->icmp(Builder::INT_NE, $owned, $i8->constInt(0, false));
        $freeBb = BasicBlockHelper::append($context, 'rpb_user_free');
        $skipBb = BasicBlockHelper::append($context, 'rpb_user_free_skip');
        $context->builder->branchIf($isOwned, $freeBb, $skipBb);
        $context->builder->positionAtEnd($freeBb);
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->builder->load($bodySlot)
        );
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
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
