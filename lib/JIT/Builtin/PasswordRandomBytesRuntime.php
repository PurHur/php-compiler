<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Production random_bytes bridge for password_hash nested JIT (#9275, #22313).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer FtokRuntime #22300).
 * Avoids user-script __compiler_random_bytes null stub during AOT password crypto.
 * SSOT: {@see \PHPCompiler\ext\standard\RandomBytesJitHelper}
 */
final class PasswordRandomBytesRuntime
{
    private const ABI = '__compiler_password_random_bytes';

    private const HELPER_PATH = '/ext/standard/RandomBytesJitHelper.php';

    private const RANDOM_BYTES_HELPER = 'PHPCompiler\\ext\\standard\\RandomBytesJitHelper::randomBytes';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RANDOM_BYTES_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);
            self::restoreInsertBlock($context, $savedBlock);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $i64)
            );

        $entry = $fn->appendBasicBlock('pw_rb_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::RANDOM_BYTES_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI, $fn);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22313');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22313'
        );
    }

    private static function restoreInsertBlock(Context $context, mixed $savedBlock): void
    {
        if (null !== $savedBlock) {
            try {
                $context->builder->positionAtEnd($savedBlock);
            } catch (\Throwable) {
                $context->builder->clearInsertionPosition();
            }

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
