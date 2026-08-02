<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_filter_validate_int_string via FilterIntJitHelper PHP (#11757, #26699).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringFilterMac #25019 / StringFilterIp #24650).
 * php-src: ext/filter/logical_filters.c — php_filter_validate_int
 */
final class StringFilterInt
{
    private const HELPER_PATH = '/ext/filter/FilterIntJitHelper.php';

    private const VALIDATE_HELPER = 'PHPCompiler\\ext\\filter\\FilterIntJitHelper::validateString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALIDATE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_validate_int_string',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_int_string');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Restore caller insert block after bridge emit (#20988 / peer StringFilterMac #25019) —
        // clearInsertionPosition left the user-script builder detached
        // ("Current basic block has no parent function").
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementValidateBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementValidateBridge(Context $context): void
    {
        $abiName = '__compiler_filter_validate_int_string';
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

        $entry = $fn->appendBasicBlock('filter_int_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $helper = self::helperFunction($context, self::VALIDATE_HELPER);
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26699');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26699'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterInt bridge (#11757)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
