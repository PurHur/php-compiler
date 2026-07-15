<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\phpc_native_ht_alloc;
use PHPCompiler\ext\standard\phpc_native_ht_set_hashtable_at;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_at;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key_ht;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for multipart POST populate in user-script CGI refresh (#15624).
 *
 * User-script thin AOT uses {@see MultipartNativeJitHelper} (no nested MultipartParser JIT).
 * php-src: main/rfc1867.c
 */
final class MultipartRuntime
{
    private const HELPER_PATH = '/lib/Web/MultipartNativeJitHelper.php';

    public const POPULATE_POST_BODY_NATIVE = 'PHPCompiler\\Web\\MultipartNativeJitHelper::populatePostBodyNative';

    public const POPULATE_MULTIPART_INTO_NATIVE = 'PHPCompiler\\Web\\MultipartNativeJitHelper::populateMultipartIntoNative';

    private const LEGACY_RUNTIME_FUNCTION = '__compiler_multipart_populate_post_body';

    /** request_parse_body user-script AOT: post+files params (no sg_FILES) (#5965). */
    public const RPB_MULTIPART_RUNTIME_FUNCTION = '__compiler_rpb_multipart_populate';

    public static function ensureUserScriptLinked(Context $context): void
    {
        self::implementUserScript($context);
    }

    /**
     * Compile MultipartNativeJitHelper + RPB ABI without the sg_FILES legacy bridge (#5965).
     *
     * Used by request_parse_body() user-script AOT, which passes a local files HT
     * rather than writing CGI {@see sg_FILES}.
     */
    public static function ensurePopulateHelperCompiled(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        ParseStrRuntime::ensureUserScriptLinked($context);
        self::ensureFilesystemPrerequisites($context);
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            [self::POPULATE_POST_BODY_NATIVE, self::POPULATE_MULTIPART_INTO_NATIVE],
            '#5965'
        );
        self::implementRpbMultipartBridge($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementRpbMultipartBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::RPB_MULTIPART_RUNTIME_FUNCTION);
        if (null !== $probe && self::rpbBridgeBodyComplete($probe)) {
            $context->registerFunction(self::RPB_MULTIPART_RUNTIME_FUNCTION, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8p = $context->getTypeFromString('int8*');
        $void = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::RPB_MULTIPART_RUNTIME_FUNCTION,
                $context->context->functionType($void, false, $htPtr, $htPtr, $i8p, $i8p)
            );
        if ($fn->countBasicBlocks() > 0) {
            foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $entry = $fn->appendBasicBlock('rpb_multipart_entry');
        $early = $fn->appendBasicBlock('rpb_multipart_early');
        $work = $fn->appendBasicBlock('rpb_multipart_work');
        $context->builder->positionAtEnd($entry);

        $post = $fn->getParam(0);
        $files = $fn->getParam(1);
        $contentTypeCstr = $fn->getParam(2);
        $bodyCstr = $fn->getParam(3);
        $nullPost = $context->builder->icmp(Builder::INT_EQ, $post, $htPtr->constNull());
        $nullFiles = $context->builder->icmp(Builder::INT_EQ, $files, $htPtr->constNull());
        $context->builder->branchIf($context->builder->or($nullPost, $nullFiles), $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::POPULATE_MULTIPART_INTO_NATIVE, '#5965');
        $contentTypeStr = self::cstrDirectToPhpcString($context, $contentTypeCstr);
        $bodyStr = self::cstrDirectToPhpcString($context, $bodyCstr);
        $context->builder->call(
            $helperFn,
            JitNestedHelperCoerce::ptrToI64($context, $post),
            JitNestedHelperCoerce::ptrToI64($context, $files),
            JitNestedHelperCoerce::coerceArgForHelper($context, $contentTypeStr, $helperFn->getParam(2)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $bodyStr, $helperFn->getParam(3)->typeOf())
        );
        $context->builder->returnVoid();

        $context->registerFunction(self::RPB_MULTIPART_RUNTIME_FUNCTION, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function rpbBridgeBodyComplete(LlvmFunction $fn): bool
    {
        foreach ($fn->getBasicBlocks() as $block) {
            if ('rpb_multipart_work' === $block->getName() && null !== $block->getTerminator()) {
                return true;
            }
        }

        return false;
    }

    /** Deferred user init: linkable no-op populate for CLI refresh emit (#16075 tier-2). */
    public static function ensureUserScriptNoOpPopulateStub(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::LEGACY_RUNTIME_FUNCTION);
        if (null !== $probe && self::legacyBridgeBodyComplete($probe)) {
            $context->registerFunction(self::LEGACY_RUNTIME_FUNCTION, $probe);

            return;
        }

        $fn = null !== $probe ? $probe : self::declareLegacyFunction($context);
        $entry = $fn->appendBasicBlock('multipart_noop_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->registerFunction(self::LEGACY_RUNTIME_FUNCTION, $fn);
        $context->builder->clearInsertionPosition();
    }

    public static function implementUserScript(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        ParseStrRuntime::ensureUserScriptLinked($context);
        self::ensureFilesystemPrerequisites($context);
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            [self::POPULATE_POST_BODY_NATIVE],
            '#15624'
        );
        self::implementLegacyIfNeeded($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementLegacyIfNeeded(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::LEGACY_RUNTIME_FUNCTION);
        if (null !== $probe && self::legacyBridgeBodyComplete($probe)) {
            $context->registerFunction(self::LEGACY_RUNTIME_FUNCTION, $probe);

            return;
        }

        $fn = null !== $probe ? $probe : self::declareLegacyFunction($context);
        if ($fn->countBasicBlocks() > 0 && !self::legacyBridgeBodyComplete($fn)) {
            self::clearFunctionBody($fn);
        }
        self::implementLegacyPopulateBridge($context, $fn);
        $context->registerFunction(self::LEGACY_RUNTIME_FUNCTION, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareLegacyFunction(Context $context): LlvmFunction
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8p = $context->getTypeFromString('int8*');
        $void = $context->getTypeFromString('void');

        return $context->module->addFunction(
            self::LEGACY_RUNTIME_FUNCTION,
            $context->context->functionType($void, false, $htPtr, $i8p, $i8p)
        );
    }

    private static function implementLegacyPopulateBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('multipart_legacy_bridge_entry');
        $early = $fn->appendBasicBlock('multipart_legacy_bridge_early');
        $work = $fn->appendBasicBlock('multipart_legacy_bridge_work');
        $context->builder->positionAtEnd($entry);

        $post = $fn->getParam(0);
        $contentTypeCstr = $fn->getParam(1);
        $bodyCstr = $fn->getParam(2);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullPost = $context->builder->icmp(Builder::INT_EQ, $post, $htPtr->constNull());
        $context->builder->branchIf($nullPost, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $filesGlobal = $context->module->getNamedGlobal('sg_FILES');
        if (null === $filesGlobal) {
            throw new \LogicException('sg_FILES missing before MultipartRuntime legacy bridge (#15624)');
        }
        $files = $context->builder->load(
            $context->builder->pointerCast($filesGlobal, $htPtr->pointerType(0))
        );
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::POPULATE_POST_BODY_NATIVE, '#15624');
        $contentTypeStr = self::cstrDirectToPhpcString($context, $contentTypeCstr);
        $bodyStr = self::cstrDirectToPhpcString($context, $bodyCstr);
        $context->builder->call(
            $helperFn,
            JitNestedHelperCoerce::ptrToI64($context, $post),
            JitNestedHelperCoerce::ptrToI64($context, $files),
            JitNestedHelperCoerce::coerceArgForHelper($context, $contentTypeStr, $helperFn->getParam(2)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $bodyStr, $helperFn->getParam(3)->typeOf())
        );
        $context->builder->returnVoid();
    }

    private static function cstrDirectToPhpcString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt(
                $context->builder->call($context->lookupFunction('strlen'), $cstr),
                $i64
            ),
            $cstr
        );
    }

    private static function cstrToPhpcString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt(
                $context->builder->call($context->lookupFunction('strlen'), $cstr),
                $i64
            ),
            $cstr
        );
    }

    private static function clearFunctionBody(LlvmFunction $fn): void
    {
        foreach (array_reverse($fn->getBasicBlocks()) as $block) {
            $block->delete();
        }
    }

    private static function legacyBridgeBodyComplete(LlvmFunction $fn): bool
    {
        if (0 === $fn->countBasicBlocks()) {
            return false;
        }
        try {
            foreach ($fn->getBasicBlocks() as $block) {
                if (str_contains($block->getName(), 'multipart_legacy_bridge_work')
                    && null !== $block->getTerminator()) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::LEGACY_RUNTIME_FUNCTION);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::LEGACY_RUNTIME_FUNCTION.' missing after MultipartRuntime bridge (#15624)');
        }
        $context->registerFunction(self::LEGACY_RUNTIME_FUNCTION, $fn);
    }

    /** Register phpc_native_ht_* Internal JIT handlers before nested MultipartNativeJitHelper compile (#15624). */
    private static function ensureNativeHtInternalProxies(Context $context): void
    {
        $internals = [
            new phpc_native_ht_alloc(),
            new phpc_native_ht_set_string_key(),
            new phpc_native_ht_set_string_key_ht(),
            new phpc_native_ht_set_string_at(),
            new phpc_native_ht_set_hashtable_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }

    /** Upload temp paths in {@see MultipartNativeJitHelper} need real tempnam/file_put_contents lowering (#15624). */
    private static function ensureFilesystemPrerequisites(Context $context): void
    {
        SysGetTempDirRuntime::ensureLinked($context);
        FsDirRuntime::ensureLinked($context);
        StringFilePutContents::implement($context);
    }
}
