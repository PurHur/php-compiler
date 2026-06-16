<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tmpfile() without libc FFI — anonymous php://temp stream (#9033, #1492).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(tmpfile)
 * main/streams/php_stream_temp.c — anonymous temp stream lifecycle
 */
final class VmTmpfilePure
{
    public static function available(): bool
    {
        return true;
    }

    /**
     * @return int|false VM stream handle; buffer freed on fclose (Zend semantics)
     */
    public static function open(): int|false
    {
        return VmPhpMemoryStream::open('php://temp', 'w+b');
    }
}
