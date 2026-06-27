<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_strerror() messages without libc strerror(3) FFI (#12477).
 *
 * Linux errno strings match glibc (php-src-strict on Docker/Linux).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_strerror)
 */
final class VmPosixStrerrorPure
{
    /** @var array<int, string> */
    private const LINUX_MESSAGES = [
        1 => 'Operation not permitted',
        2 => 'No such file or directory',
        3 => 'No such process',
        4 => 'Interrupted system call',
        5 => 'Input/output error',
        6 => 'No such device or address',
        7 => 'Argument list too long',
        8 => 'Exec format error',
        9 => 'Bad file descriptor',
        10 => 'No child processes',
        11 => 'Resource temporarily unavailable',
        12 => 'Cannot allocate memory',
        13 => 'Permission denied',
        14 => 'Bad address',
        15 => 'Block device required',
        16 => 'Device or resource busy',
        17 => 'File exists',
        18 => 'Invalid cross-device link',
        19 => 'No such device',
        20 => 'Not a directory',
        21 => 'Is a directory',
        22 => 'Invalid argument',
        23 => 'Too many open files in system',
        24 => 'Too many open files',
        25 => 'Inappropriate ioctl for device',
        26 => 'Text file busy',
        27 => 'File too large',
        28 => 'No space left on device',
        29 => 'Illegal seek',
        30 => 'Read-only file system',
        31 => 'Too many links',
        32 => 'Broken pipe',
        33 => 'Numerical argument out of domain',
        34 => 'Numerical result out of range',
        35 => 'Resource deadlock avoided',
        36 => 'File name too long',
        37 => 'No locks available',
        38 => 'Function not implemented',
        39 => 'Directory not empty',
        40 => 'Too many levels of symbolic links',
        122 => 'Disk quota exceeded',
        124 => 'Wrong medium type',
        125 => 'Operation canceled',
        126 => 'Required key not available',
        127 => 'Key has expired',
        128 => 'Key has been revoked',
        129 => 'Key was rejected by service',
        130 => 'Owner died',
        131 => 'State not recoverable',
        132 => 'Operation not possible due to RF-kill',
        133 => 'Memory page has hardware error',
        134 => 'Unknown error 134',
    ];

    public static function message(int $errno): string
    {
        return self::LINUX_MESSAGES[$errno] ?? ('Unknown error '.$errno);
    }
}
