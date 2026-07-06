<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for class_implements() via ClassImplementsJitHelper PHP (#16960).
 *
 * Replaces inline class-id scan LLVM in ext/standard/JitClassImplements.php.
 * SSOT: {@see \PHPCompiler\ext\standard\ClassImplementsJitHelper}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_implements)
 */
final class StringClassImplements
{
    private const ABI = '__phpc_jit_class_implements';

    private const HELPER_PATH = '/ext/standard/ClassImplementsJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ClassImplementsJitHelper::implementsArgv';

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

    public static function invoke(Context $context, Value $objectOrClass, Value $autoload): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $objectOrClass,
            $autoload
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

        $valuePtr = $context->getTypeFromString('__value__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'class_implements_bridge_entry',
            [$valuePtr, $i1],
            $valuePtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16960'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
