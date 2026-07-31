<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Directory open failure classification (php-src ext/standard/dir.c; #18418).
 *
 * php-src: php_opendir / php_scandir wrapper dir_opener vs plain_wrapper paths
 * User wrappers: main/streams/userspace.c php_userstream_dir_opener (#26002).
 */
final class VmDirOpenFailure
{
    public static function isNonDirectoryWrapperPath(string $path): bool
    {
        return VmFsPhpWrapper::isPhpWrapperPath($path)
            || VmDataUri::isDataUri($path);
    }

    /** php-src dir.c — first E_WARNING reason string for Failed to open directory. */
    public static function openDirFailureReason(string $path): string
    {
        if (self::isNonDirectoryWrapperPath($path)) {
            return 'not implemented';
        }
        if (VmStreamWrapperRegistry::isCustomProtocol($path)) {
            $protocol = VmStreamWrapperRegistry::parseProtocol($path);
            $className = null !== $protocol ? VmStreamWrapperRegistry::lookupClass($protocol) : null;
            if (null !== $className) {
                // userspace.c UserspaceCallFailed / missing dir_opendir
                return \sprintf('"%s::dir_opendir" call failed', $className);
            }
        }
        if (VmStatPath::isFile($path)) {
            return 'Not a directory';
        }

        return 'No such file or directory';
    }

    /** php-src dir.c — scandir()-only follow-up errno warning after open failure. */
    public static function scandirFollowupWarning(string $path): string
    {
        if (self::isNonDirectoryWrapperPath($path)
            || VmStreamWrapperRegistry::isCustomProtocol($path)
        ) {
            return 'scandir(): (errno 0): Success';
        }
        if (VmStatPath::isFile($path)) {
            return 'scandir(): (errno 20): Not a directory';
        }

        return 'scandir(): (errno 2): No such file or directory';
    }
}
