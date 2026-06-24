<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_strtr* via Strtr*JitHelper PHP (#9392).
 *
 * JIT embed compiles PHP SSOT helpers; AOT standalone keeps {@see StringStrtrStandaloneLlvm}
 * for array form until HashTable iteration compiles in native standalone nested link.
 * php-src: ext/standard/string.c
 */
final class StringStrtr
{
    private const TWO_STRING_HELPER_PATH = '/ext/standard/StrtrTwoStringJitHelper.php';

    private const ARRAY_HELPER_PATH = '/ext/standard/StrtrArrayJitHelper.php';

    private const STRTR_TWO_STRING = 'PHPCompiler\\ext\\standard\\StrtrTwoStringJitHelper::strtrTwoString';

    private const STRTR_ARRAY = 'PHPCompiler\\ext\\standard\\StrtrArrayJitHelper::strtrArray';

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_strtr',
        '__compiler_strtr_array',
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
        $twoStringProbe = $context->module->getNamedFunction('__compiler_strtr');
        $arrayProbe = $context->module->getNamedFunction('__compiler_strtr_array');
        if (null !== $twoStringProbe && $twoStringProbe->countBasicBlocks() > 0
            && null !== $arrayProbe && $arrayProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureTwoStringHelperCompiled($context);
        self::implementTwoStringBridge($context);

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StringStrtrStandaloneLlvm::implement($context);
        } else {
            self::ensureArrayHelperCompiled($context);
            self::implementArrayBridge($context);
        }

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementTwoStringBridge(Context $context): void
    {
        $abiName = '__compiler_strtr';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strtr_two_string_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::STRTR_TWO_STRING),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementArrayBridge(Context $context): void
    {
        $abiName = '__compiler_strtr_array';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $htPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strtr_array_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::STRTR_ARRAY),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after Strtr JIT helper compile (#9392)');
        }

        return $fn;
    }

    private static function ensureTwoStringHelperCompiled(Context $context): void
    {
        if (isset($context->functions[\strtolower(self::STRTR_TWO_STRING)])) {
            return;
        }

        self::compileHelperFile($context, self::TWO_STRING_HELPER_PATH, 'StrtrTwoStringJitHelper.php');
        if (!isset($context->functions[\strtolower(self::STRTR_TWO_STRING)])) {
            throw new \LogicException(self::STRTR_TWO_STRING.' was not compiled for JIT (#9392)');
        }
    }

    private static function ensureArrayHelperCompiled(Context $context): void
    {
        if (isset($context->functions[\strtolower(self::STRTR_ARRAY)])) {
            return;
        }

        self::compileHelperFile($context, self::ARRAY_HELPER_PATH, 'StrtrArrayJitHelper.php');
        if (!isset($context->functions[\strtolower(self::STRTR_ARRAY)])) {
            throw new \LogicException(self::STRTR_ARRAY.' was not compiled for JIT (#9392)');
        }
    }

    private static function compileHelperFile(Context $context, string $relativePath, string $label): void
    {
        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).$relativePath;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $label): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), $label);
            if (null === $block) {
                throw new \LogicException($label.' parseAndCompile failed (#9392)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringStrtr bridge (#9392)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
