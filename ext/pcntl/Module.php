<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/** pcntl extension module entry (php-src ext/pcntl/pcntl.c; issue #6680). */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach (PcntlConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new pcntl_fork(),
            new pcntl_wait(),
            new pcntl_waitpid(),
            new pcntl_wifexited(),
            new pcntl_wexitstatus(),
            new pcntl_wifsignaled(),
            new pcntl_wifstopped(),
            new pcntl_wtermsig(),
            new pcntl_wstopsig(),
            new pcntl_alarm(),
            new pcntl_exec(),
            new pcntl_signal(),
            new pcntl_signal_dispatch(),
            new pcntl_async_signals(),
            new pcntl_signal_get_handler(),
            new pcntl_sigprocmask(),
            new pcntl_sigtimedwait(),
            ...(CompilerVersion::supportsPhp84PcntlApis() ? [
                new pcntl_waitid(),
                new pcntl_getcpuaffinity(),
                new pcntl_setcpuaffinity(),
                new pcntl_getcpu(),
                new pcntl_setns(),
            ] : []),
            new pcntl_getpriority(),
            new pcntl_setpriority(),
            new pcntl_unshare(),
            new pcntl_strerror(),
            new pcntl_get_last_error(),
            new pcntl_errno(),
            new pcntl_wifcontinued(),
            new pcntl_sigwaitinfo(),
        ];
    }
}
