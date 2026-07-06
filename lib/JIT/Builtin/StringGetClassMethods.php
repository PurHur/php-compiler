<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for get_class_methods() via GetClassMethodsJitHelper PHP (#16729).
 *
 * Replaces inline class-id scan / strcasecmp LLVM in ext/standard/JitGetClassMethods.php.
 * SSOT: {@see \PHPCompiler\ext\standard\GetClassMethodsJitHelper}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_class_methods)
 */
final class StringGetClassMethods
{
    private const ABI = '__phpc_jit_get_class_methods';

    private const HELPER_PATH = '/ext/standard/GetClassMethodsJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\GetClassMethodsJitHelper::methodsArgv';

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

    public static function invoke(Context $context, Value $objectOrClass): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $objectOrClass
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'get_class_methods_bridge_entry',
            [$valuePtr],
            $valuePtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16729'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
