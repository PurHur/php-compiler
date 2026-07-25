<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ffi;

/**
 * php-src @not-serializable FFI opaque handles (#23133):
 * - FFI / FFI\CData / FFI\CType — ext/ffi/ffi.stub.php
 */
final class FfiSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmFFI::CLASS_LC,
        VmFFI::CLASS_CDATA_LC,
        VmFFI::CLASS_CTYPE_LC,
    ];

    public static function rejectSerialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Serialization of '".self::displayName($className)."' is not allowed");
        }
    }

    public static function rejectUnserialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Unserialization of '".self::displayName($className)."' is not allowed");
        }
    }

    private static function isDenied(string $className): bool
    {
        return \in_array(strtolower(ltrim($className, '\\')), self::DENIED_LC, true);
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
