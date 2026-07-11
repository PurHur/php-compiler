<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for function_exists() via FunctionExistsJitHelper PHP (#9239, #16424).
 *
 * Replaces inline user-function LLVM scan in ext/standard/JitFunctionExists.php.
 * SSOT: {@see \PHPCompiler\ext\standard\FunctionExistsJitHelper}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(function_exists)
 */
final class StringFunctionExists
{
    private const ABI = '__phpc_jit_function_exists';

    private const HELPER_PATH = '/ext/standard/FunctionExistsJitHelper.php';

    private const BUILTIN_EXISTS_HELPER = 'PHPCompiler\\ext\\standard\\FunctionExistsJitHelper::builtinExists';

    private const EXISTS_HELPER = 'PHPCompiler\\ext\\standard\\FunctionExistsJitHelper::existsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BUILTIN_EXISTS_HELPER,
        self::EXISTS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $nameStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $nameStr
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);
            FunctionExistsRuntime::ensureLinked($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#16424');
        self::implementExistsBridge($context);
        FunctionExistsRuntime::ensureLinked($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementExistsBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('function_exists_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::EXISTS_HELPER, '#16424');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$fn->getParam(0)]
        );
        $exists = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($exists);

        $context->registerFunction(self::ABI, $fn);
    }
}
