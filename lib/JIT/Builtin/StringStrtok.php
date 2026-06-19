<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_strtok via StrtokJitHelper PHP (#9812).
 *
 * Replaces former ~480-line LLVM buffer/table walk with thin bridges into {@see VmString} SSOT.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strtok)
 */
final class StringStrtok
{
    private const HELPER_PATH = '/ext/standard/StrtokJitHelper.php';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\StrtokJitHelper::reset';

    private const INIT_HELPER = 'PHPCompiler\\ext\\standard\\StrtokJitHelper::init';

    private const TOKENIZE_HELPER = 'PHPCompiler\\ext\\standard\\StrtokJitHelper::tokenize';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESET_HELPER,
        self::INIT_HELPER,
        self::TOKENIZE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_strtok',
        '__phpc_strtok_reset',
        '__phpc_strtok_init',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_strtok');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementResetBridge($context);
        self::implementInitBridge($context);
        self::implementTokenizeBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementResetBridge(Context $context): void
    {
        $abiName = '__phpc_strtok_reset';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strtok_reset_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, self::RESET_HELPER));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementInitBridge(Context $context): void
    {
        $abiName = '__phpc_strtok_init';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($voidTy, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strtok_init_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::INIT_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTokenizeBridge(Context $context): void
    {
        $abiName = 'phpc_strtok';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strtok_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::TOKENIZE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
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
            throw new \LogicException($logical.' missing after StrtokJitHelper compile (#9812)');
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StrtokJitHelper.php');
        if (null === $block) {
            throw new \LogicException('StrtokJitHelper.php parseAndCompile failed (#9812)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9812)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringStrtok bridge (#9812)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
