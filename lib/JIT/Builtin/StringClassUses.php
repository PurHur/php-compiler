<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for class_uses() via ClassUsesJitHelper PHP (#16501).
 *
 * Replaces inline strcasecmp LLVM in ext/standard/JitClassUses.php.
 * SSOT: {@see \PHPCompiler\ext\standard\ClassUsesJitHelper}.
 * php-src: ext/standard/spl_functions.c — PHP_FUNCTION(class_uses)
 */
final class StringClassUses
{
    private const ABI = '__phpc_jit_class_uses';

    private const HELPER_PATH = '/ext/standard/ClassUsesJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ClassUsesJitHelper::usesArgv';

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
            'class_uses_bridge_entry',
            [$valuePtr, $i1],
            $valuePtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16501'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
