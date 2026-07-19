<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\EmbedObOutput;

/**
 * MCJIT embed runtime setup (#98, #2055).
 */
final class McjitEmbedRuntime
{
    private static bool $librariesLoaded = false;

    public static function prepareModule(Context $context): void
    {
        if (Builtin::LOAD_TYPE_EMBED !== $context->loadType) {
            return;
        }
        self::ensureLibrariesLoaded($context);
    }

    public static function finalizeModule(Context $context): void
    {
        if (Builtin::LOAD_TYPE_EMBED !== $context->loadType) {
            return;
        }
        EmbedObOutput::implement($context);
    }

    private static function ensureLibrariesLoaded(Context $context): void
    {
        if (self::$librariesLoaded) {
            return;
        }
        $lib = $context->llvm->lib;
        $lib->LLVMLoadLibraryPermanently(null);
        foreach (['/lib/x86_64-linux-gnu/libc.so.6', '/lib64/libc.so.6', '/usr/lib/x86_64-linux-gnu/libc.so.6'] as $candidate) {
            if (is_file($candidate)) {
                $lib->LLVMLoadLibraryPermanently($candidate);
                break;
            }
        }
        $phpBinary = PHP_BINARY;
        if (is_string($phpBinary) && '' !== $phpBinary && is_executable($phpBinary)) {
            $lib->LLVMLoadLibraryPermanently($phpBinary);
        }
        self::registerHostLibcSymbols($lib);
        self::$librariesLoaded = true;
    }

    /**
     * MCJIT does not always resolve libc via LoadLibraryPermanently alone on
     * php-compiler:22.04-dev — llvm.memset/memcpy then lower to call-through-null
     * (#98, #2055, #21109). Pin common symbols into LLVM's DynamicLibrary table.
     *
     * @param object $lib PHPLLVM FFI llvm binding (LLVMAddSymbol / SearchForAddressOfSymbol)
     */
    private static function registerHostLibcSymbols(object $lib): void
    {
        if (!\method_exists($lib, 'LLVMSearchForAddressOfSymbol') || !\method_exists($lib, 'LLVMAddSymbol')) {
            return;
        }
        foreach ([
            'memset', 'memcpy', 'memmove', 'memcmp', 'memchr',
            'strlen', 'strcmp', 'strncmp', 'strcasecmp', 'strncasecmp',
            'malloc', 'calloc', 'realloc', 'free',
            'abort', 'exit', 'snprintf', 'fprintf', 'fwrite',
            'fopen', 'fclose', 'open', 'close', 'read', 'write',
            'php_write', 'syscall',
        ] as $symbol) {
            $addr = $lib->LLVMSearchForAddressOfSymbol($symbol);
            if (null !== $addr) {
                $lib->LLVMAddSymbol($symbol, $addr);
            }
        }
        // Custom MCJIT symbols → real host addrs (declared-only libc names often relocate to null).
        self::aliasHostSymbol($lib, '__phpc_host_php_write', 'php_write');
        self::aliasHostSymbol($lib, '__phpc_host_snprintf', 'snprintf');
    }

    /** @param object $lib PHPLLVM FFI llvm binding */
    private static function aliasHostSymbol(object $lib, string $alias, string $real): void
    {
        $addr = $lib->LLVMSearchForAddressOfSymbol($real);
        if (null !== $addr) {
            $lib->LLVMAddSymbol($alias, $addr);
        }
    }
}
