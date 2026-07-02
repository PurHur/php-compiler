<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __string__bitwiseNot via StringBitwiseNotJitHelper PHP (#14823).
 *
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StringBitwiseNotJitHelper compile (#14823)');
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
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StringBitwiseNotJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StringBitwiseNotJitHelper.php parseAndCompile failed (#14823)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#14823)');
            }
        }
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
