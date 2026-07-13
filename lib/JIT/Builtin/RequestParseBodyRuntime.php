<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\phpc_native_ht_alloc;
use PHPCompiler\ext\standard\phpc_native_ht_set_hashtable_at;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_at;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key_ht;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MultipartRuntime;
use PHPCompiler\JIT\Builtin\ParseStrRuntime;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for request_parse_body() via RequestParseBodyJitHelper PHP (#16927).
 *
 * Emits a lightweight LLVM bridge calling a nested-compiled PHP helper that reads
 * env CONTENT_TYPE + REQUEST_BODY and writes to native hashtables.
 */
final class RequestParseBodyRuntime
{
    private const HELPER_PATH = '/ext/standard/RequestParseBodyJitHelper.php';

    private const USER_SCRIPT_HELPER_PATH = '/ext/standard/RequestParseBodyNativeJitHelper.php';

    private const PARSE_INTO_NATIVE_HELPER = 'PHPCompiler\\ext\\standard\\RequestParseBodyJitHelper::parseIntoNative';

    private const USER_SCRIPT_PARSE_INTO_NATIVE = 'PHPCompiler\\ext\\standard\\RequestParseBodyNativeJitHelper::parseIntoNative';

    /** @var list<string> */
    private const USER_SCRIPT_COMPILED_HELPERS = [
        self::USER_SCRIPT_PARSE_INTO_NATIVE,
    ];

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_INTO_NATIVE_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_request_parse_body',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        if (StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            self::ensureUserScriptJitHelperCompiled($context);

            return;
        }

        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16927'
        );
    }

    public static function ensureUserScriptJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::USER_SCRIPT_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        MultipartRuntime::ensureUserScriptLinked($context);
        ParseStrRuntime::ensureUserScriptLinked($context);
        StringGetenv::ensureJitHelperCompiled($context);
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::USER_SCRIPT_HELPER_PATH,
            self::USER_SCRIPT_COMPILED_HELPERS,
            '#5965'
        );
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after RequestParseBody helper compile (#16927)');
        }

        return $fn;
    }

    public static function parseIntoNativeHelperLogical(Context $context): string
    {
        return $context->isThinStandaloneAotMain()
            ? self::USER_SCRIPT_PARSE_INTO_NATIVE
            : self::PARSE_INTO_NATIVE_HELPER;
    }

    public static function implement(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);

            return;
        }

        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16927'
        );

        self::implementIfMissing(
            $context,
            '__compiler_request_parse_body',
            static function (Context $context, LlvmFunction $fn): void {
                self::implementParseBridge($context, $fn, self::PARSE_INTO_NATIVE_HELPER);
            }
        );

        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && 0 !== $probe->countBasicBlocks()) {
            $context->registerFunction($name, $probe);
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);

            return;
        }

        $fn = null !== $probe ? $probe : self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $void = $context->getTypeFromString('void');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($void, false, $htPtr, $htPtr)
        );
    }

    private static function implementParseBridge(Context $context, LlvmFunction $fn, string $helperLogical): void
    {
        $entry = $fn->appendBasicBlock('request_parse_body_bridge_entry');
        $early = $fn->appendBasicBlock('request_parse_body_bridge_early');
        $work = $fn->appendBasicBlock('request_parse_body_bridge_work');
        $context->builder->positionAtEnd($entry);

        $post = $fn->getParam(0);
        $files = $fn->getParam(1);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullPost = $context->builder->icmp(Builder::INT_EQ, $post, $htPtr->constNull());
        $nullFiles = $context->builder->icmp(Builder::INT_EQ, $files, $htPtr->constNull());
        $anyNull = $context->builder->or($nullPost, $nullFiles);
        $context->builder->branchIf($anyNull, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#16927');
        $postI64 = JitNestedHelperCoerce::ptrToI64($context, $post);
        $filesI64 = JitNestedHelperCoerce::ptrToI64($context, $files);
        $optionsNull = $helperFn->getParam(2)->typeOf()->constNull();
        $context->builder->call(
            $helperFn,
            $postI64,
            $filesI64,
            JitNestedHelperCoerce::coerceArgForHelper($context, $optionsNull, $helperFn->getParam(2)->typeOf())
        );
        $context->builder->returnVoid();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after RequestParseBodyRuntime bridge (#16927)');
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

    /** Register phpc_native_ht_* Internal JIT handlers before nested helper compile (#16927). */
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
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }
}

