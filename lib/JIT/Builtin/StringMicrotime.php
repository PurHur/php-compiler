<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_microtime_* via MicrotimeJitHelper PHP (#9181, #23556).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathModf #22519).
 * Replaces gettimeofday/snprintf LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(microtime)
 */
final class StringMicrotime
{
    private const HELPER_PATH = '/ext/standard/MicrotimeJitHelper.php';

    private const FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\MicrotimeJitHelper::microtimeFloat';

    private const STRING_HELPER = 'PHPCompiler\\ext\\standard\\MicrotimeJitHelper::microtimeString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FLOAT_HELPER,
        self::STRING_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $strProbe = $context->module->getNamedFunction('__compiler_microtime_string');
        if (null !== $strProbe && $strProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementFloatBridge($context);
        self::implementStringBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFloatBridge(Context $context): void
    {
        $abiName = '__compiler_microtime_float';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $doubleTy = $context->getTypeFromString('double');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($doubleTy, false)
            );

        $entry = $fn->appendBasicBlock('microtime_float_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, self::FLOAT_HELPER));
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementStringBridge(Context $context): void
    {
        $abiName = '__compiler_microtime_string';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false)
            );

        $entry = $fn->appendBasicBlock('microtime_string_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, self::STRING_HELPER));
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23556');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23556'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_microtime_string', '__compiler_microtime_float'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringMicrotime bridge (#23556)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
