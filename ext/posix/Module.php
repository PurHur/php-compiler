<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * posix extension module entry (php-src ext/posix/posix.c; issue #7105).
 *
 * v1 libc wrappers: #7271; access/mknod/set*: #7376; full port tracked in #3339 / #7177.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach ([
            'POSIX_F_OK' => PosixConstants::POSIX_F_OK,
            'POSIX_R_OK' => PosixConstants::POSIX_R_OK,
            'POSIX_W_OK' => PosixConstants::POSIX_W_OK,
            'POSIX_X_OK' => PosixConstants::POSIX_X_OK,
            'S_IFIFO' => PosixConstants::S_IFIFO,
            'S_IFCHR' => PosixConstants::S_IFCHR,
            'S_IFDIR' => PosixConstants::S_IFDIR,
            'S_IFBLK' => PosixConstants::S_IFBLK,
            'S_IFREG' => PosixConstants::S_IFREG,
            'S_IFLNK' => PosixConstants::S_IFLNK,
            'S_IFSOCK' => PosixConstants::S_IFSOCK,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new posix_getpid(),
            new posix_getppid(),
            new posix_getegid(),
            new posix_strerror(),
            new posix_get_last_error(),
            new posix_getcwd(),
            new posix_ctermid(),
            new posix_errno(),
            new posix_access(),
            new posix_mknod(),
            new posix_setuid(),
            new posix_setgid(),
            new posix_seteuid(),
            new posix_setegid(),
        ];
    }
}
