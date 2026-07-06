<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for get_class_vars() via GetClassVarsJitHelper PHP (#16713).
 *
 * Replaces inline LLVM in ext/standard/JitGetClassVars.php.
 * SSOT: {@see \PHPCompiler\ext\standard\GetClassVarsJitHelper}.
 * php-src: ext/standard/class.c — PHP_FUNCTION(get_class_vars)
 */
final class StringGetClassVars
{
    private const ABI = '__phpc_jit_get_class_vars';

    private const HELPER_PATH = '/ext/standard/GetClassVarsJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\GetClassVarsJitHelper::classVarsArgv';

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

    public static function invoke(Context $context, Value $classOperand): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $classOperand
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
            'get_class_vars_bridge_entry',
            [$valuePtr],
            $valuePtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16713'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
