<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MultipartRuntime;
use PHPCompiler\JIT\Builtin\ParseStrRuntime;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script standalone AOT: init-safe LLVM for request_parse_body() (#19466, #5965, #17316).
 *
 * Reads REQUEST_BODY / CONTENT_TYPE via libc getenv (mirrored from putenv() via POSIX setenv).
 * strdup's the body before strtok/urldecode in-place parse so environ is not mutated.
 * Multipart uses {@see MultipartNativeJitHelper::populateMultipartIntoNative} (prelinked;
 * no sg_FILES legacy bridge). Media-type detect uses libc `strncmp` — not `strncasecmp`,
 * which may resolve to CaseCompareJitHelper's PHP string ABI in deferred AOT (#5965).
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19454 / #19399.
 * php-src: ext/standard/http.c
 */
final class JitRequestParseBodyKernel
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
        // Module-local strncmp after LibcExtern always-on drop (#31839).
        LibcExtern::ensureStrncmp($context);
        StringGetenv::ensureLibcGetenv($context);
        ParseStrRuntime::ensureUserScriptLinked($context);
        MultipartRuntime::ensurePopulateHelperCompiled($context);
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
        $ctRaw = $context->builder->call(
            $context->lookupFunction('getenv'),
            $context->pointerFromStringConstant('CONTENT_TYPE')
        );
        $ctNull = $context->builder->icmp(Builder::INT_EQ, $ctRaw, $i8p->constNull());
        $contentType = $context->builder->select(
            $ctNull,
            $context->pointerFromStringConstant(''),
            $ctRaw
        );

        $post = $fn->getParam(0);
        $files = $fn->getParam(1);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullPost = $context->builder->icmp(Builder::INT_EQ, $post, $htPtr->constNull());
        $nullFiles = $context->builder->icmp(Builder::INT_EQ, $files, $htPtr->constNull());
        $anyNull = $context->builder->or($nullPost, $nullFiles);
        $early = $fn->appendBasicBlock('rpb_user_early');
        $work = $fn->appendBasicBlock('rpb_user_work');
        $context->builder->branchIf($anyNull, $early, $work);

        $context->builder->positionAtEnd($early);
        self::emitFreeOwnedBody($context, $bodySlot, $ownedSlot);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $bodyEmpty = self::isCstrSlotEmpty($context, $bodySlot);
        $done = $fn->appendBasicBlock('rpb_user_done');
        $parse = $fn->appendBasicBlock('rpb_user_parse');
        $context->builder->branchIf($bodyEmpty, $done, $parse);

        $context->builder->positionAtEnd($parse);
        $multipartBb = $fn->appendBasicBlock('rpb_user_multipart');
        $urlencodedBb = $fn->appendBasicBlock('rpb_user_urlencoded');
        $checkMatchBb = $fn->appendBasicBlock('rpb_user_ct_match');
        $contentTypeEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($contentType),
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        // Empty CONTENT_TYPE → urlencoded (php-src default). Do not fold into "done":
        // a mis-scheduled and(not empty, match) previously jumped empty CT to free/return (#5965).
        $context->builder->branchIf($contentTypeEmpty, $urlencodedBb, $checkMatchBb);

        $context->builder->positionAtEnd($checkMatchBb);
        $needle = $context->pointerFromStringConstant('multipart/form-data');
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $contentType,
            $needle,
            $context->constantFromInteger(19, 'size_t')
        );
        $isMultipart = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $context->builder->branchIf($isMultipart, $multipartBb, $urlencodedBb);

        $context->builder->positionAtEnd($multipartBb);
        self::emitMultipartPopulate($context, $post, $files, $contentType, $bodySlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($urlencodedBb);
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

    private static function emitMultipartPopulate(
        Context $context,
        Value $post,
        Value $files,
        Value $contentTypeCstr,
        Value $bodySlot
    ): void {
        // Call init-linked ABI (post+files) — mirrors CGI legacy bridge, no sg_FILES (#5965).
        $context->builder->call(
            $context->lookupFunction(MultipartRuntime::RPB_MULTIPART_RUNTIME_FUNCTION),
            $post,
            $files,
            $contentTypeCstr,
            $context->builder->load($bodySlot)
        );
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
            if ('rpb_user_multipart' === $block->getName() && null !== $block->getTerminator()) {
                return true;
            }
        }

        return false;
    }
}
