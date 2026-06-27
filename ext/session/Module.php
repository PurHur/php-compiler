<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * session extension module entry (php-src ext/session/session.c; issue #6004).
 *
 * Lifecycle LLVM: {@see \PHPCompiler\JIT\Builtin\SessionLifecycleRuntime} (#5332). #6002 adds lifecycle APIs.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach ([
            'PHP_SESSION_DISABLED' => SessionConstants::PHP_SESSION_DISABLED,
            'PHP_SESSION_NONE' => SessionConstants::PHP_SESSION_NONE,
            'PHP_SESSION_ACTIVE' => SessionConstants::PHP_SESSION_ACTIVE,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new session_start(),
            new session_id(),
            new session_name(),
            new session_module_name(),
            new session_status(),
            new session_destroy(),
            new session_write_close(),
            new session_commit(),
            new session_regenerate_id(),
            new session_abort(),
            new session_reset(),
            new session_create_id(),
            new session_encode(),
            new session_decode(),
            new session_unset(),
            new session_gc(),
        ];
    }
}
