<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * session_get_cookie_params() — read session cookie configuration (php-src ext/session/session.c; #9982).
 */
class session_get_cookie_params_ extends Internal
{
    public function __construct()
    {
        parent::__construct('session_get_cookie_params');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'session_get_cookie_params() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(VmSession::cookieParamsHashTable());
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSessionCookieAndPath::invokeGetCookieParams($context, ...$args);
    }
}
