<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for vsprintf() via VsprintfJitHelper PHP (#15989).
 *
 * Replaces direct __compiler_sprintf argv dispatch tail in ext/standard/JitVsprintf.php.
 * SSOT: {@see \PHPCompiler\ext\standard\SprintfJitHelper}.
 * php-src: ext/standard/sprintf.c — PHP_FUNCTION(vsprintf)
 */
final class StringVsprintf
{
    private const ABI = '__phpc_jit_vsprintf_packed';

    private const HELPER_PATH = '/ext/standard/VsprintfJitHelper.php';

    private const FORMAT_HELPER = 'PHPCompiler\\ext\\standard\\VsprintfJitHelper::formatPackedArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokePacked(Context $context, Value $format, Value $packedArgv): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $format,
            $packedArgv
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

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'vsprintf_packed_bridge_entry',
            [$strPtr, $strPtr],
            $strPtr,
            self::FORMAT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15989'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
