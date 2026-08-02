<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_filter_validate_ip via FilterIpJitHelper PHP (#4403, #24650).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringFilterBoolean #23612).
 * php-src: ext/filter/logical_filters.c — php_filter_validate_ip
 */
final class StringFilterIp
{
    private const HELPER_PATH = '/ext/filter/FilterIpJitHelper.php';

    private const VALIDATE_HELPER = 'PHPCompiler\\ext\\filter\\FilterIpJitHelper::validate';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALIDATE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_validate_ip',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_ip');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Restore caller insert block after bridge emit (#20988 / peer StringFilterBoolean #23612) —
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
        $abiName = '__compiler_filter_validate_ip';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('filter_ip_bridge_entry');
        $context->builder->positionAtEnd($entry);
        // NestedJIT ?string may be __value__*; ABI is __string__* (#26853).
        $helper = self::helperFunction($context, self::VALIDATE_HELPER);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helper,
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24650');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24650'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterIp bridge (#4403)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
