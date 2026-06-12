<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php_uname() via libc uname(2) — no host Zend php_uname builtin delegation (#8171, pairs #6124 StringInfo).
 *
 * php-src: ext/standard/info.c — PHP_FUNCTION(php_uname)
 */
final class VmUnameNative
{
    private static ?\FFI $ffi = null;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function php_uname(string $mode = 'a'): string
    {
        $uts = self::utsname();
        if (null === $uts) {
            return '';
        }

        return match ($mode[0] ?? 'a') {
            's' => $uts['sysname'],
            'n' => $uts['nodename'],
            'r' => $uts['release'],
            'v' => $uts['version'],
            'm' => $uts['machine'],
            default => \sprintf(
                '%s %s %s %s %s',
                $uts['sysname'],
                $uts['nodename'],
                $uts['release'],
                $uts['version'],
                $uts['machine']
            ),
        };
    }

    /**
     * @return array{sysname: string, nodename: string, release: string, version: string, machine: string}|null
     */
    private static function utsname(): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        $buf = $ffi->new('struct utsname');
        if (0 !== (int) $ffi->uname(\FFI::addr($buf))) {
            return null;
        }

        return [
            'sysname' => \FFI::string($buf->sysname),
            'nodename' => \FFI::string($buf->nodename),
            'release' => \FFI::string($buf->release),
            'version' => \FFI::string($buf->version),
            'machine' => \FFI::string($buf->machine),
        ];
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }

        $cdef = <<<'CDEF'
struct utsname {
    char sysname[65];
    char nodename[65];
    char release[65];
    char version[65];
    char machine[65];
};
int uname(struct utsname *buf);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
