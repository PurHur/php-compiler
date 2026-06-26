<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_json_decode via JsonDecodeJitHelper PHP (#9359).
 *
 * JIT/normal modules use compiled {@see JsonDecodeJitHelper}; AOT standalone keeps
 * {@see StringJsonDecodeJit} until native link can host compiled VmJson reliably.
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

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StringJsonDecodeJit::ensureStandaloneBodies($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_json_decode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementDecodeBridge($context);
        self::implementValidateBridge($context);
        self::implementLastErrorBridge($context);
        self::implementLastErrorMsgBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementDecodeBridge(Context $context): void
    {
        $abiName = '__compiler_json_decode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_decode_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $decoded = $context->builder->call(
            self::helperFunction($context, self::DECODE_HELPER),
            $fn->getParam(0)
        );
        JitValueBox::copyIntoPointer($context, $fn->getParam(1), $decoded);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementValidateBridge(Context $context): void
    {
        $abiName = '__compiler_json_validate';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_validate_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(
            self::helperFunction($context, self::VALIDATE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLastErrorBridge(Context $context): void
    {
        $abiName = '__compiler_json_last_error';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_last_error_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(self::helperFunction($context, self::LAST_ERROR_HELPER));
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLastErrorMsgBridge(Context $context): void
    {
        $abiName = '__compiler_json_last_error_msg';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_last_error_msg_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(self::helperFunction($context, self::LAST_ERROR_MSG_HELPER));
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
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
