<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * session extension module entry (php-src ext/session/session.c; issue #6004).
 *
 * Builtin bodies remain in ext/standard until #5332 migrates session C and #6002 adds lifecycle APIs.
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
            new session_destroy(),
            new session_write_close(),
            new session_regenerate_id(),
        ];
    }
}
