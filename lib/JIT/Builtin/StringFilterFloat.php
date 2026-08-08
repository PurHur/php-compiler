<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for FILTER_VALIDATE_FLOAT string parse via FilterFloatJitHelper (#29013).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_float
 */
final class StringFilterFloat
{
    private const HELPER_PATH = '/ext/filter/FilterFloatJitHelper.php';

    private const IS_VALID_HELPER = 'PHPCompiler\\ext\\filter\\FilterFloatJitHelper::isValidString';

    private const PARSE_HELPER = 'PHPCompiler\\ext\\filter\\FilterFloatJitHelper::parseValue';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_VALID_HELPER,
        self::PARSE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_validate_float_string',
        '__compiler_filter_parse_float_string',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_float_string');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementIsValidBridge($context);
        self::implementParseBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementIsValidBridge(Context $context): void
    {
        $abiName = '__compiler_filter_validate_float_string';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('filter_float_valid_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $helper = self::helperFunction($context, self::IS_VALID_HELPER);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helper,
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementParseBridge(Context $context): void
    {
        $abiName = '__compiler_filter_parse_float_string';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $ft = $context->context->functionType($dbl, false, $strPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('filter_float_parse_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $helper = self::helperFunction($context, self::PARSE_HELPER);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helper,
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $dbl)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#29013');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29013'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterFloat bridge (#29013)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
