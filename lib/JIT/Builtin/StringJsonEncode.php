<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_json_encode_* via JsonEncodeJitHelper PHP (#9267, #13239).
 *
 * Embed and standalone AOT compile the same PHP bridge; no json_encode LLVM monolith.
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class StringJsonEncode
{
    private const HELPER_PATH = '/ext/standard/JsonEncodeJitHelper.php';

    private const ENCODE_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\JsonEncodeJitHelper::encodeValue';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_VALUE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_json_encode_value',
        '__compiler_json_encode_array',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_json_encode_value');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::emitValueBridge($context);
        self::emitArrayBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function emitValueBridge(Context $context): void
    {
        $abiName = '__compiler_json_encode_value';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $valuePtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_encode_value_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::ENCODE_VALUE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    /** Box __hashtable__* as __value__* — avoids Variable::array() in nested JIT (#13245). */
    private static function emitArrayBridge(Context $context): void
    {
        $abiName = '__compiler_json_encode_array';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $htPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_encode_array_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $valueSlot = JitValueBox::alloc($context);
        $valuePtr = JitValueBox::pointer($context, $valueSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valuePtr,
            $fn->getParam(0)
        );
        $result = $context->builder->call(
            self::helperFunction($context, self::ENCODE_VALUE_HELPER),
            $valuePtr,
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after JsonEncodeJitHelper compile (#9267)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'JsonEncodeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('JsonEncodeJitHelper.php parseAndCompile failed (#9267)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9267)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringJsonEncode bridge (#9267)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
