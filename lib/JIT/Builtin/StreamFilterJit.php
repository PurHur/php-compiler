<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_filter_* via StreamFilterJitHelper PHP (#9047).
 *
 * php-src: ext/standard/streamsfuncs.c — stream_filter_append/prepend/remove/register
 */
final class StreamFilterJit
{
    private const HELPER_PATH = '/ext/standard/StreamFilterJitHelper.php';

    private const APPEND_HELPER = 'PHPCompiler\\ext\\standard\\StreamFilterJitHelper::append';

    private const PREPEND_HELPER = 'PHPCompiler\\ext\\standard\\StreamFilterJitHelper::prepend';

    private const REMOVE_HELPER = 'PHPCompiler\\ext\\standard\\StreamFilterJitHelper::remove';

    private const REGISTER_HELPER = 'PHPCompiler\\ext\\standard\\StreamFilterJitHelper::register';

    private const IS_VALID_HELPER = 'PHPCompiler\\ext\\standard\\StreamFilterJitHelper::isValidHandle';

    private const APPLY_WRITE_HELPER = 'PHPCompiler\\ext\\standard\\StreamFilterJitHelper::applyWriteFilters';

    private const APPLY_READ_HELPER = 'PHPCompiler\\ext\\standard\\StreamFilterJitHelper::applyReadFilters';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::APPEND_HELPER,
        self::PREPEND_HELPER,
        self::REMOVE_HELPER,
        self::REGISTER_HELPER,
        self::IS_VALID_HELPER,
        self::APPLY_WRITE_HELPER,
        self::APPLY_READ_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_stream_filter_append',
        '__compiler_stream_filter_prepend',
        '__compiler_stream_filter_remove',
        '__compiler_stream_filter_register',
        '__compiler_is_stream_filter_resource',
        '__compiler_stream_filter_apply_write',
        '__compiler_stream_filter_apply_read',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_filter_append');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementAppendBridge($context);
        self::implementPrependBridge($context);
        self::implementRemoveBridge($context);
        self::implementRegisterBridge($context);
        self::implementIsValidBridge($context);
        self::implementApplyWriteBridge($context);
        self::implementApplyReadBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementAppendBridge(Context $context): void
    {
        self::implementTernaryI64Bridge(
            $context,
            '__compiler_stream_filter_append',
            self::APPEND_HELPER
        );
    }

    private static function implementPrependBridge(Context $context): void
    {
        self::implementTernaryI64Bridge(
            $context,
            '__compiler_stream_filter_prepend',
            self::PREPEND_HELPER
        );
    }

    private static function implementTernaryI64Bridge(Context $context, string $abiName, string $helper): void
    {
        if (self::bridgeAlreadyImplemented($context, $abiName)) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($i64, false, $i64, $strPtr, $i64);
        $fn = self::declareOrReuse($context, $abiName, $ft);
        $entry = $fn->appendBasicBlock($abiName.'_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helper),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRemoveBridge(Context $context): void
    {
        self::implementUnaryI32Bridge($context, '__compiler_stream_filter_remove', self::REMOVE_HELPER);
    }

    private static function implementIsValidBridge(Context $context): void
    {
        self::implementUnaryI32Bridge($context, '__compiler_is_stream_filter_resource', self::IS_VALID_HELPER);
    }

    private static function implementUnaryI32Bridge(Context $context, string $abiName, string $helper): void
    {
        if (self::bridgeAlreadyImplemented($context, $abiName)) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i64);
        $fn = self::declareOrReuse($context, $abiName, $ft);
        $entry = $fn->appendBasicBlock($abiName.'_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, $helper), $fn->getParam(0));
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRegisterBridge(Context $context): void
    {
        if (self::bridgeAlreadyImplemented($context, '__compiler_stream_filter_register')) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $strPtr, $strPtr);
        $fn = self::declareOrReuse($context, '__compiler_stream_filter_register', $ft);
        $entry = $fn->appendBasicBlock('stream_filter_register_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::REGISTER_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_stream_filter_register', $fn);
    }

    private static function implementApplyWriteBridge(Context $context): void
    {
        self::implementApplyBridge($context, '__compiler_stream_filter_apply_write', self::APPLY_WRITE_HELPER);
    }

    private static function implementApplyReadBridge(Context $context): void
    {
        self::implementApplyBridge($context, '__compiler_stream_filter_apply_read', self::APPLY_READ_HELPER);
    }

    private static function implementApplyBridge(Context $context, string $abiName, string $helper): void
    {
        if (self::bridgeAlreadyImplemented($context, $abiName)) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i64, $strPtr);
        $fn = self::declareOrReuse($context, $abiName, $ft);
        $entry = $fn->appendBasicBlock($abiName.'_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helper),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function bridgeAlreadyImplemented(Context $context, string $abiName): bool
    {
        $probe = $context->module->getNamedFunction($abiName);

        return null !== $probe && $probe->countBasicBlocks() > 0;
    }

    /**
     * @param mixed $ft
     */
    private static function declareOrReuse(Context $context, string $abiName, $ft): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe) {
            return $probe;
        }

        return $context->module->addFunction($abiName, $ft);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamFilterJitHelper compile (#9047)');
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
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamFilterJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamFilterJitHelper.php parseAndCompile failed (#9047)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9047)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamFilterJit bridge (#9047)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
