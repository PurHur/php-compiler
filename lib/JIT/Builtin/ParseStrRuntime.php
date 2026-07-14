<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\phpc_native_ht_alloc;
use PHPCompiler\ext\standard\phpc_native_ht_set_hashtable_at;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_at;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key_ht;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_parse_str via ParseStrJitHelper PHP (#9295, #14217).
 *
 * Embed compiles {@see ParseStrJitHelper}; user-script AOT compiles {@see ParseStrNativeJitHelper} (#15417).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrRuntime
{
    private const HELPER_PATH = '/ext/standard/ParseStrJitHelper.php';

    private const USER_SCRIPT_HELPER_PATH = '/ext/standard/ParseStrNativeJitHelper.php';

    private const PARSE_INTO_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseInto';

    private const PARSE_INTO_NATIVE_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseIntoNative';

    private const PARSE_COOKIE_INTO_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseCookieHeaderInto';

    private const PARSE_COOKIE_INTO_NATIVE_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseCookieHeaderIntoNative';

    private const USER_SCRIPT_PARSE_INTO_NATIVE = 'PHPCompiler\\ext\\standard\\ParseStrNativeJitHelper::parseIntoNative';

    private const USER_SCRIPT_PARSE_COOKIE_INTO_NATIVE = 'PHPCompiler\\ext\\standard\\ParseStrNativeJitHelper::parseCookieHeaderIntoNative';

    /** @var list<string> */
    private const USER_SCRIPT_COMPILED_HELPERS = [
        self::USER_SCRIPT_PARSE_INTO_NATIVE,
        self::USER_SCRIPT_PARSE_COOKIE_INTO_NATIVE,
    ];

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_INTO_HELPER,
        self::PARSE_INTO_NATIVE_HELPER,
        self::PARSE_COOKIE_INTO_HELPER,
        self::PARSE_COOKIE_INTO_NATIVE_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_parse_str',
        '__compiler_parse_cookie_header',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** User-script AOT: native parse_str without full ParseStrJitHelper nested JIT (#15417). */
    public static function ensureUserScriptLinked(Context $context): void
    {
        self::implementUserScript($context);
    }

    public static function implementUserScript(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::USER_SCRIPT_HELPER_PATH,
            self::USER_SCRIPT_COMPILED_HELPERS,
            '#15417'
        );
        self::implementIfMissing($context, '__compiler_parse_str', static function (Context $context, LlvmFunction $fn): void {
            self::implementUserScriptParseBridge($context, $fn);
        });
        self::implementIfMissing($context, '__compiler_parse_cookie_header', static function (Context $context, LlvmFunction $fn): void {
            self::implementUserScriptCookieBridge($context, $fn);
        });
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** User-script AOT init: linkable parse_str ABI without nested ParseStrJitHelper JIT (#15417). */
    public static function ensureDeferredStubsForUserScriptRefresh(Context $context): void
    {
        if (!StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            self::implement($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $probe = $context->module->getNamedFunction($name);
            if (null !== $probe && $probe->countBasicBlocks() > 0) {
                $context->registerFunction($name, $probe);
                continue;
            }
            $fn = self::declareFunction($context, $name);
            $entry = $fn->appendBasicBlock($name.'_user_refresh_stub');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnVoid();
            $context->registerFunction($name, $fn);
        }
        self::registerLinkedRuntime($context);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#9299'
        );
        self::implementIfMissing($context, '__compiler_parse_str', static function (Context $context, LlvmFunction $fn): void {
            self::implementParseBridge($context, $fn, self::PARSE_INTO_NATIVE_HELPER);
        });
        self::implementIfMissing($context, '__compiler_parse_cookie_header', static function (Context $context, LlvmFunction $fn): void {
            self::implementCookieBridge($context, $fn, self::PARSE_COOKIE_INTO_NATIVE_HELPER);
        });
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && self::bridgeBodyComplete($probe)) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = null !== $probe && $probe->countBasicBlocks() > 0 ? $probe : self::declareFunction($context, $name);
        if (null !== $probe && $probe->countBasicBlocks() > 0 && !self::bridgeBodyComplete($probe)) {
            self::clearFunctionBody($fn);
        }
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($void, false, $htPtr, $strPtr)
        );
    }

    private static function implementParseBridge(Context $context, LlvmFunction $fn, string $helperLogical): void
    {
        $entry = $fn->appendBasicBlock('parse_str_bridge_entry');
        $early = $fn->appendBasicBlock('parse_str_bridge_early');
        $work = $fn->appendBasicBlock('parse_str_bridge_work');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $encoded = $fn->getParam(1);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullDest = $context->builder->icmp(Builder::INT_EQ, $dest, $htPtr->constNull());
        $context->builder->branchIf($nullDest, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#13827');
        $destI64 = JitNestedHelperCoerce::ptrToI64($context, $dest);
        $i64 = $context->getTypeFromString('int64');
        $destSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($destI64, $destSlot);
        $destArg = $context->builder->load($destSlot);
        $encodedArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $encoded,
            $helperFn->getParam(1)->typeOf()
        );
        JitNestedHelperCoerce::callHelper($context, $helperFn, [$destArg, $encodedArg]);
        $context->builder->returnVoid();
    }

    private static function implementUserScriptParseBridge(Context $context, LlvmFunction $fn): void
    {
        self::implementParseBridge($context, $fn, self::USER_SCRIPT_PARSE_INTO_NATIVE);
    }

    private static function implementUserScriptCookieBridge(Context $context, LlvmFunction $fn): void
    {
        self::implementCookieBridge($context, $fn, self::USER_SCRIPT_PARSE_COOKIE_INTO_NATIVE);
    }

    private static function implementCookieBridge(Context $context, LlvmFunction $fn, string $helperLogical): void
    {
        $entry = $fn->appendBasicBlock('parse_cookie_bridge_entry');
        $early = $fn->appendBasicBlock('parse_cookie_bridge_early');
        $work = $fn->appendBasicBlock('parse_cookie_bridge_work');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $header = $fn->getParam(1);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullDest = $context->builder->icmp(Builder::INT_EQ, $dest, $htPtr->constNull());
        $context->builder->branchIf($nullDest, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#13827');
        $destI64 = JitNestedHelperCoerce::ptrToI64($context, $dest);
        $i64 = $context->getTypeFromString('int64');
        $destSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($destI64, $destSlot);
        $destArg = $context->builder->load($destSlot);
        $headerArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $header,
            $helperFn->getParam(1)->typeOf()
        );
        JitNestedHelperCoerce::callHelper($context, $helperFn, [$destArg, $headerArg]);
        $context->builder->returnVoid();
    }

    private static function bridgeBodyComplete(LlvmFunction $fn): bool
    {
        if (0 === $fn->countBasicBlocks()) {
            return false;
        }
        try {
            foreach ($fn->getBasicBlocks() as $block) {
                $name = $block->getName();
                if (
                    (str_contains($name, '_work') || str_contains($name, '_bridge_work'))
                    && null !== $block->getTerminator()
                ) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private static function clearFunctionBody(LlvmFunction $fn): void
    {
        foreach (array_reverse($fn->getBasicBlocks()) as $block) {
            $block->delete();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ParseStrRuntime bridge (#9295)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    /** Register phpc_native_ht_* Internal JIT handlers before nested ParseStrJitHelper compile (#13900). */
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
}
