<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for crc32()/crc32c() via Crc32JitHelper PHP (#15759).
 *
 * Replaces inline CRC table LLVM in ext/standard/JitCrcCore.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmCrc32}, {@see \PHPCompiler\ext\standard\VmCrc32c}.
 */
final class Crc32Runtime
{
    private const ABI_CRC32 = 'phpc_crc32_compute';

    private const ABI_CRC32C = 'phpc_crc32c_compute';

    private const HELPER_PATH = '/ext/standard/Crc32JitHelper.php';

    private const CRC32_HELPER = 'PHPCompiler\\ext\\standard\\Crc32JitHelper::crc32Argv';

    private const CRC32C_HELPER = 'PHPCompiler\\ext\\standard\\Crc32JitHelper::crc32cArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CRC32_HELPER,
        self::CRC32C_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementCrc32($context);
        self::implementCrc32c($context);
    }

    public static function invokeCrc32(Context $context, Value $subject, Value $seed): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CRC32),
            $subject,
            $seed
        );
    }

    public static function invokeCrc32c(Context $context, Value $subject): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CRC32C),
            $subject
        );
    }

    private static function implementCrc32(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CRC32,
            'crc32_bridge_entry',
            [$strPtr, $i64],
            $i64,
            self::CRC32_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15759'
        );
    }

    private static function implementCrc32c(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CRC32C,
            'crc32c_bridge_entry',
            [$strPtr],
            $i64,
            self::CRC32C_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15759'
        );
    }
}
