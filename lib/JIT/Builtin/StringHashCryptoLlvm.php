<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * LLVM bridges for hash crypto on user-script standalone AOT (#3357, #16734).
 *
 * Nested HashCryptoJitHelper during user-script compile segfaults; link helpers once at
 * standalone init via {@see JitVmHelperLink} (same pattern as file_get_contents #15309).
 * php-src: ext/standard/hash.c, ext/standard/hash_hmac.c
 */
final class StringHashCryptoLlvm
{
    private const HELPER_PATH = '/ext/standard/HashCryptoJitHelper.php';

    private const HASH_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hash';

    private const HMAC_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashHmac';

    private const PBKDF2_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashPbkdf2';

    private const HKDF_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashHkdf';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HASH_HELPER,
        self::HMAC_HELPER,
        self::PBKDF2_HELPER,
        self::HKDF_HELPER,
    ];

    public static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_hash',
            'hash_crypto_llvm_hash',
            [$strPtr, $strPtr, $i32],
            $strPtr,
            self::HASH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#3357'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_hash_hmac',
            'hash_crypto_llvm_hmac',
            [$strPtr, $strPtr, $strPtr, $i32],
            $strPtr,
            self::HMAC_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#3357'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_hash_pbkdf2',
            'hash_crypto_llvm_pbkdf2',
            [$strPtr, $strPtr, $strPtr, $i64, $i64, $i32],
            $strPtr,
            self::PBKDF2_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#3357'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_hash_hkdf',
            'hash_crypto_llvm_hkdf',
            [$strPtr, $strPtr, $i64, $strPtr, $strPtr],
            $strPtr,
            self::HKDF_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#3357'
        );
    }
}
