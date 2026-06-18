<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for addcslashes/stripcslashes via CslashesJitHelper PHP (#5652, #9578).
 *
 * Replaces former ~440-line LLVM mask/decode layer with thin bridges into {@see VmString} SSOT.
 */
final class StringCslashes
{
    private const HELPER_PATH = '/ext/standard/CslashesJitHelper.php';

    private const ADDCslashes_HELPER = 'PHPCompiler\\ext\\standard\\CslashesJitHelper::addcslashes';

    private const STRIPCslashes_HELPER = 'PHPCompiler\\ext\\standard\\CslashesJitHelper::stripcslashes';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ADDCslashes_HELPER,
        self::STRIPCslashes_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStripcslashes(Context $context): void
    {
        self::implementStripcslashes($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
        self::implementStripcslashes($context);
    }

    public static function implement(Context $context): void
    {
        self::implementBridge($context, '__compiler_addcslashes', self::ADDCslashes_HELPER, 2);
    }

    public static function implementStripcslashes(Context $context): void
    {
        self::implementBridge($context, '__compiler_stripcslashes', self::STRIPCslashes_HELPER, 1);
    }

    private static function implementBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $paramCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $params = \array_fill(0, $paramCount, $strPtr);
        $ft = $context->context->functionType($strPtr, false, ...$params);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('cslashes_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $callArgs = [];
        for ($i = 0; $i < $paramCount; ++$i) {
            $callArgs[] = $fn->getParam($i);
        }
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            ...$callArgs
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after CslashesJitHelper compile (#9578)');
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'CslashesJitHelper.php');
        if (null === $block) {
            throw new \LogicException('CslashesJitHelper.php parseAndCompile failed (#9578)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9578)');
            }
        }
    }
}
