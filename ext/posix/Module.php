<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * posix extension module entry (php-src ext/posix/posix.c; issue #7105).
 *
 * v1 libc wrappers: #7271; access/mknod/set*: #7376; host delegation removed #7177; times/rlimit: #7173; euid/groups/uname: #6123.
 * posix_sysconf/pathconf/fpathconf/eaccess + POSIX_SC_* / PC_*: PHP 8.3+ (#20509, #22483).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach (PosixConstants::registeredConstants() as $name => $value) {
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
            new posix_getuid(),
            new posix_geteuid(),
            new posix_getgid(),
            new posix_getegid(),
            new posix_getgroups(),
            new posix_uname(),
            new posix_strerror(),
            new posix_get_last_error(),
            new posix_getcwd(),
            new posix_ctermid(),
            new posix_errno(),
            new posix_access(),
            ...(CompilerVersion::supportsPosixSysconfApis() ? [
                new posix_eaccess(),
                new posix_sysconf(),
                new posix_pathconf(),
                new posix_fpathconf(),
            ] : []),
            new posix_mknod(),
            new posix_mkfifo(),
            new posix_setuid(),
            new posix_setgid(),
            new posix_seteuid(),
            new posix_setegid(),
            new posix_times(),
            new posix_getrlimit(),
            new posix_setrlimit(),
            new posix_setsid(),
            new posix_getsid(),
            new posix_getpgid(),
            new posix_getpgrp(),
            new posix_setpgid(),
            new posix_initgroups(),
            new posix_kill(),
            new posix_getlogin(),
            new posix_ttyname(),
            new posix_isatty(),
            new posix_getpwuid(),
            new posix_getpwnam(),
            new posix_getgrgid(),
            new posix_getgrnam(),
        ];
    }
}
