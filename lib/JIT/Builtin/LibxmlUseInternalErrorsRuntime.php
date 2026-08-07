<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for libxml_use_internal_errors() via LibxmlInternalErrorsJitHelper (#28659).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GcToggleRuntime #9687).
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_use_internal_errors)
 */
final class LibxmlUseInternalErrorsRuntime
{
    private const HELPER_PATH = '/ext/libxml/LibxmlInternalErrorsJitHelper.php';

    private const EXCHANGE_HELPER = 'PHPCompiler\\ext\\libxml\\LibxmlInternalErrorsJitHelper::exchange';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXCHANGE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_libxml_use_internal_errors',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_libxml_use_internal_errors');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementExchangeBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementExchangeBridge(Context $context): void
    {
        $abiName = '__compiler_libxml_use_internal_errors';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i32, $i32)
            );

        $entry = $fn->appendBasicBlock('libxml_use_internal_errors_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $hasNew = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $fn->getParam(0),
            $i32->constInt(0, false)
        );
        $newValue = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $fn->getParam(1),
            $i32->constInt(0, false)
        );
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::EXCHANGE_HELPER),
            [$hasNew, $newValue]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#28659');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28659'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after LibxmlUseInternalErrorsRuntime bridge (#28659)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
