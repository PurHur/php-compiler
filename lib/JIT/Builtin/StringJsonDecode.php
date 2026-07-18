<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_json_decode via JsonDecodeJitHelper PHP (#9359, #13228, #20380).
 *
 * Embed / non-thin: NestedJIT {@see JsonDecodeJitHelper} (#13228).
 * Thin standalone AOT (`isThinStandaloneAotMain`, #20371 / #20355 shape): thin stubs without nested JIT (#13245).
 * php-src: ext/json/php_json.c — php_json_decode_ex
 */
final class StringJsonDecode
{
    private const HELPER_PATH = '/ext/standard/JsonDecodeJitHelper.php';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::decode';

    private const VALIDATE_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::validate';

    private const LAST_ERROR_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::lastError';

    private const LAST_ERROR_MSG_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::lastErrorMsg';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DECODE_HELPER,
        self::VALIDATE_HELPER,
        self::LAST_ERROR_HELPER,
        self::LAST_ERROR_MSG_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_json_decode',
        '__compiler_json_validate',
        '__compiler_json_last_error',
        '__compiler_json_last_error_msg',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Standalone AOT: JSON POST helper for superglobals_refresh.c (#7389). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    /** Thin standalone AOT: linkable json_decode ABI without nested JsonDecodeJitHelper (#13245, #20380). */
    public static function ensureDeferredStubsForInventoryEmit(Context $context): void
    {
        if (!$context->isThinStandaloneAotMain()) {
            return;
        }
        StringJsonDecodeInventoryStubs::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_json_decode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            StringJsonDecodeInventoryStubs::implement($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementBridges($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridges(Context $context): void
    {
        self::implementVoidValueOutBridge($context, '__compiler_json_decode', 'json_decode_bridge_entry', self::DECODE_HELPER);
        self::implementInt64Bridge($context, '__compiler_json_validate', 'json_validate_bridge_entry', self::VALIDATE_HELPER, true);
        self::implementInt64Bridge($context, '__compiler_json_last_error', 'json_last_error_bridge_entry', self::LAST_ERROR_HELPER, false);
        self::implementStringPtrBridge($context, '__compiler_json_last_error_msg', 'json_last_error_msg_bridge_entry', self::LAST_ERROR_MSG_HELPER);
    }

    private static function implementVoidValueOutBridge(
        Context $context,
        string $abiName,
        string $blockName,
        string $helper
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock($blockName);
        $context->builder->positionAtEnd($entry);
        $decoded = $context->builder->call(self::helperFunction($context, $helper), $fn->getParam(0));
        JitValueBox::copyIntoPointer($context, $fn->getParam(1), $decoded);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementInt64Bridge(
        Context $context,
        string $abiName,
        string $blockName,
        string $helper,
        bool $withDepthArg
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $params = $withDepthArg ? [$strPtr, $i64] : [];
        $ft = $context->context->functionType($i64, false, ...$params);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock($blockName);
        $context->builder->positionAtEnd($entry);
        $args = $withDepthArg ? [$fn->getParam(0), $fn->getParam(1)] : [];
        $raw = $context->builder->call(self::helperFunction($context, $helper), ...$args);
        $context->builder->returnValue(JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementStringPtrBridge(
        Context $context,
        string $abiName,
        string $blockName,
        string $helper
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock($blockName);
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(self::helperFunction($context, $helper));
        $context->builder->returnValue(JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw));
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after JsonDecodeJitHelper compile (#9359)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
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

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'JsonDecodeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('JsonDecodeJitHelper.php parseAndCompile failed (#9359)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9359)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringJsonDecode bridge (#9359)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
