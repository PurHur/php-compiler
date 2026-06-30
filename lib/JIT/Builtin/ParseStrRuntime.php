<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_parse_str via ParseStrJitHelper PHP (#9295).
 *
 * Embed AOT uses {@see ParseStrJitHelper::parseIntoNative} nested JIT materializer.
 * User-script AOT uses {@see ParseStrUserScriptDelimitedJit} init-safe LLVM (#13571, #13900).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrRuntime
{
    private const HELPER_PATH = '/ext/standard/ParseStrJitHelper.php';

    private const PARSE_INTO_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseInto';

    private const PARSE_INTO_NATIVE_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseIntoNative';

    private const PARSE_COOKIE_INTO_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseCookieHeaderInto';

    private const PARSE_COOKIE_INTO_NATIVE_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseCookieHeaderIntoNative';

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

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#9299'
        );
        if (self::isUserScriptStandaloneAot()) {
            ParseStrUserScriptDelimitedJit::ensureSubhelpers($context);
        }
        self::implementIfMissing($context, '__compiler_parse_str', self::implementParseBridge(...));
        self::implementIfMissing($context, '__compiler_parse_cookie_header', self::implementCookieBridge(...));
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
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
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

    private static function implementParseBridge(Context $context, LlvmFunction $fn): void
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
        if (self::isUserScriptStandaloneAot()) {
            self::emitUserScriptDelimitedParse($context, $dest, $encoded, false);
            $context->builder->returnVoid();

            return;
        }
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::PARSE_INTO_NATIVE_HELPER, '#13827');
        $destI64 = JitNestedHelperCoerce::ptrToI64($context, $dest);
        $encodedArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $encoded,
            $helperFn->getParam(1)->typeOf()
        );
        $context->builder->call($helperFn, $destI64, $encodedArg);
        $context->builder->returnVoid();
    }

    private static function implementCookieBridge(Context $context, LlvmFunction $fn): void
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
        if (self::isUserScriptStandaloneAot()) {
            self::emitUserScriptDelimitedParse($context, $dest, $header, true);
            $context->builder->returnVoid();

            return;
        }
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::PARSE_COOKIE_INTO_NATIVE_HELPER, '#13827');
        $destI64 = JitNestedHelperCoerce::ptrToI64($context, $dest);
        $headerArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $header,
            $helperFn->getParam(1)->typeOf()
        );
        $context->builder->call($helperFn, $destI64, $headerArg);
        $context->builder->returnVoid();
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

    private static function isUserScriptStandaloneAot(): bool
    {
        $flag = getenv('PHP_COMPILER_AOT_USER_SCRIPT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    private static function emitUserScriptDelimitedParse(
        Context $context,
        \PHPLLVM\Value $dest,
        \PHPLLVM\Value $encoded,
        bool $cookiePairDecode
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $cstr = $context->builder->structGep($encoded, $context->structFieldMap['__string__']['value']);
        $delimiter = $cookiePairDecode ? $i8->constInt(59, false) : $i8->constInt(38, false);
        $flags = $cookiePairDecode ? $i32->constInt(1, false) : $i32->constInt(0, false);
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_parse_delimited_pairs'),
            $dest,
            $cstr,
            $delimiter,
            $flags
        );
    }
}
