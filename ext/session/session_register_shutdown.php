<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * session_register_shutdown() — register session_write_close on script shutdown (php-src ext/session/session.c; #4873).
 */
final class session_register_shutdown extends Internal
{
    public function __construct()
    {
        parent::__construct('session_register_shutdown');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('session_register_shutdown() takes no arguments in this compiler build');
        }
        SessionUserHandler::registerShutdown();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'session_register_shutdown() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
