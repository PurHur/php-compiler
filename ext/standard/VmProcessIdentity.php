<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;

/**
 * Process identity builtins (issue #6119, pure /proc #9017, #12182).
 *
 * php-src: ext/standard/basic_functions.c — getmyuid, getmygid, get_current_user.
 * No host \\posix_* / \\get*() delegation — VmProcessIdentityPure / VmProcessIdentityNative only.
 */
final class VmProcessIdentity
{
    public static function getmyuid(): int
    {
        $uid = VmProcessIdentityNative::getuid();
        if (null !== $uid) {
            return $uid;
        }

        throw new \LogicException('getmyuid() requires POSIX support in this compiler build');
    }

    public static function getmygid(): int
    {
        $gid = VmProcessIdentityNative::getgid();
        if (null !== $gid) {
            return $gid;
        }

        throw new \LogicException('getmygid() requires POSIX support in this compiler build');
    }

    public static function getCurrentUser(): string
    {
        return self::getCurrentUserForScript('');
    }

    /**
     * Login name of the executed script file owner (basic_functions.c php_get_current_user).
     *
     * Returns '' when there is no on-disk script path (stdin / eval harness).
     */
    public static function getCurrentUserForScript(string $scriptPath): string
    {
        if (self::isVirtualScriptPath($scriptPath)) {
            return '';
        }
        $uid = VmFs::fileOwner($scriptPath);
        if (false === $uid) {
            return '';
        }
        $name = VmProcessIdentityNative::getpwuidName($uid);
        if (null === $name) {
            return '';
        }

        return $name;
    }

    public static function executedFilename(Frame $frame): string
    {
        if (null !== $frame->vmContext) {
            $root = $frame->vmContext->scriptStack->root();
            if ('' !== $root) {
                return $root;
            }
        }
        $f = $frame;
        while (null !== $f->parent) {
            $f = $f->parent;
        }
        if ('' !== $f->scriptPath) {
            return $f->scriptPath;
        }
        if (null !== $f->block) {
            return $f->block->scriptPath();
        }

        return '';
    }

    private static function isVirtualScriptPath(string $path): bool
    {
        return '' === $path
            || '-' === $path
            || 'Standard input code' === $path
            || 'Command line code' === $path;
    }
}
