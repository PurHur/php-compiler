<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __string__bitwiseNot via StringBitwiseNotJitHelper PHP (#14823, #24513).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ChownRuntime #24473).
 * Replaces ~76 LOC inline LLVM in StringBitwiseNot.php.
 * SSOT: {@see \PHPCompiler\VM\Variable::unaryOp()} on string operands.
 * php-src: Zend/zend_operators.c
 */
final class StringBitwiseNot
{
    private const HELPER_PATH = '/ext/standard/StringBitwiseNotJitHelper.php';

    private const BITWISE_NOT_HELPER = 'PHPCompiler\\ext\\standard\\StringBitwiseNotJitHelper::bitwiseNotArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BITWISE_NOT_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__string__bitwiseNot',
    ];

    public static function register(Context $context): void
    {
        $fnType = $context->context->functionType(
            $context->getTypeFromString('__string__*'),
            false,
            $context->getTypeFromString('__string__*')
        );
        $fn = $context->module->addFunction('__string__bitwiseNot', $fnType);
        $fn->addAttributeAtIndex(\PHPLLVM\Attribute::INDEX_FUNCTION, $context->attributes['alwaysinline']);
        $context->registerFunction('__string__bitwiseNot', $fn);
    }

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
        $probe = $context->module->getNamedFunction('__string__bitwiseNot');
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
        self::implementBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__string__bitwiseNot';
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

        $entry = $fn->appendBasicBlock('bitwise_not_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::BITWISE_NOT_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24513');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24513'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringBitwiseNot bridge (#14823)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
