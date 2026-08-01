<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_ini_parse_quantity via IniParseQuantityJitHelper PHP (#9237, #26444).
 *
 * Replaces former strtoll/suffix LLVM with thin bridge into {@see VmIniQuantity} SSOT.
 * php-src: Zend/zend_ini.c — zend_ini_parse_quantity
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer HtmlEntityDecodeJit #26441 / IconvRuntime #25570).
 */
final class IniParseQuantityRuntime
{
    private const HELPER_PATH = '/ext/standard/IniParseQuantityJitHelper.php';

    private const PARSE_HELPER = 'PHPCompiler\\ext\\standard\\IniParseQuantityJitHelper::parseQuantity';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_ini_parse_quantity',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ini_parse_quantity');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementParseBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementParseBridge(Context $context): void
    {
        $abiName = '__compiler_ini_parse_quantity';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ini_parse_quantity_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::PARSE_HELPER, '#26444');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26444'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after IniParseQuantityRuntime bridge (#9237)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
