<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for class_exists() via ClassExistsJitHelper PHP (#16185).
 *
 * Replaces inline strcasecmp LLVM loop in ext/standard/JitClassExists.php.
 * SSOT: {@see \PHPCompiler\ext\standard\ClassExistsJitHelper}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_exists)
 */
final class StringClassExists
{
    private const ABI = '__phpc_jit_class_exists';

    private const HELPER_PATH = '/ext/standard/ClassExistsJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ClassExistsJitHelper::existsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
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

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#16185');

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('class_exists_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#16185');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$fn->getParam(0)]
        );
        $exists = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($exists);

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
