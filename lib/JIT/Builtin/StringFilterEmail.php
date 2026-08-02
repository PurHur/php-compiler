<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_filter_validate_email via FilterEmailJitHelper PHP (#9860).
 *
 * Helper compile: chained {@see JitVmHelperLink::ensureCompiled} —
 * FilterEmailValidate → FilterEmailJitHelper (#22826 NestedJIT lean unit).
 * php-src: ext/filter/logical_filters.c — php_filter_validate_email
 */
final class StringFilterEmail
{
    private const VALIDATE_PATH = '/ext/filter/FilterEmailValidate.php';

    private const HELPER_PATH = '/ext/filter/FilterEmailJitHelper.php';

    private const VALIDATE_IS_VALID = 'PHPCompiler\\ext\\filter\\FilterEmailValidate::isValid';

    private const VALIDATE_HELPER = 'PHPCompiler\\ext\\filter\\FilterEmailJitHelper::validate';

    /** @var list<string> */
    private const COMPILED_VALIDATE = [
        self::VALIDATE_IS_VALID,
    ];

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALIDATE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_validate_email',
    ];

    public static function ensureLinked(Context $context): void
    {
        $restore = $context->builder->getInsertBlock();
        self::implement($context);
        if (null !== $restore) {
            $terminator = $restore->getTerminator();
            if (null !== $terminator) {
                $context->builder->positionBefore($terminator);
            } else {
                $context->builder->positionAtEnd($restore);
            }
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_email');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementValidateBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementValidateBridge(Context $context): void
    {
        $abiName = '__compiler_filter_validate_email';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('filter_email_bridge_entry');
        $context->builder->positionAtEnd($entry);
        // NestedJIT ?string may be __value__*; ABI is __string__* (#26853).
        $helper = self::helperFunction($context, self::VALIDATE_HELPER);
        $raw = JitNestedHelperCoerce::callHelper($context, $helper, [$fn->getParam(0)]);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#9860');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::VALIDATE_PATH, self::COMPILED_VALIDATE, '#22826');
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#9860');
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterEmail bridge (#9860)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
