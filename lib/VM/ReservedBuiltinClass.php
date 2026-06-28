<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend reserved internal classes — userland `new` / `implements` guards (#13324, #13327).
 *
 * php-src: Zend/zend_closures.c, Zend/zend_generators.c, ext/spl/php_spl.c, ext/curl/interface.c
 */
final class ReservedBuiltinClass
{
    /** @var array<string, string> lc => Error message */
    private const USER_INSTANTIATION_FORBIDDEN = [
        'closure' => 'Instantiation of class Closure is not allowed',
        'generator' => 'The "Generator" class is reserved for internal use and cannot be manually instantiated',
        'curlhandle' => 'Cannot directly construct CurlHandle, use curl_init() instead',
        'curlmultihandle' => 'Cannot directly construct CurlMultiHandle, use curl_multi_init() instead',
        'curlsharehandle' => 'Cannot directly construct CurlShareHandle, use curl_share_init() instead',
    ];

    /** @var array<string, string> lc => display name — compile-time implements guard (#13327) */
    private const COMPILE_TIME_NON_INTERFACES = [
        'internaliterator' => 'InternalIterator',
    ];

    public static function userInstantiationErrorMessage(string $classLc): ?string
    {
        return self::USER_INSTANTIATION_FORBIDDEN[strtolower(ltrim($classLc, '\\'))] ?? null;
    }

    public static function assertUserInstantiable(ClassEntry $entry): void
    {
        $msg = self::userInstantiationErrorMessage($entry->name);
        if (null !== $msg) {
            throw new \Error($msg);
        }
    }

    public static function compileTimeNonInterfaceDisplayName(string $ifaceLc): ?string
    {
        return self::COMPILE_TIME_NON_INTERFACES[strtolower(ltrim($ifaceLc, '\\'))] ?? null;
    }

    public static function compileTimeImplementsForbiddenMessage(string $subjectDisplay, string $ifaceLc): ?string
    {
        $nonIface = self::compileTimeNonInterfaceDisplayName($ifaceLc);
        if (null === $nonIface) {
            return null;
        }

        return sprintf('%s cannot implement %s - it is not an interface', $subjectDisplay, $nonIface);
    }
}
