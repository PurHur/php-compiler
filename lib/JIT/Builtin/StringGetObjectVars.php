<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for get_object_vars() via GetObjectVarsJitHelper PHP (#16629).
 *
 * Replaces inline class-id scan LLVM in ext/standard/JitGetObjectVars.php.
 * SSOT: {@see \PHPCompiler\ext\standard\GetObjectVarsJitHelper}.
 * php-src: ext/standard/var.c — PHP_FUNCTION(get_object_vars)
 */
final class StringGetObjectVars
{
    private const ABI = '__phpc_jit_get_object_vars';

    private const HELPER_PATH = '/ext/standard/GetObjectVarsJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\GetObjectVarsJitHelper::objectVarsArgv';

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

    public static function invoke(Context $context, Value $object, Value $mangled): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $object,
            $mangled
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
            'get_object_vars_bridge_entry',
            [$valuePtr, $i1],
            $valuePtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16629'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
