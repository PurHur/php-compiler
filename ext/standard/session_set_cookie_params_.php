<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * session_set_cookie_params() — configure session cookie lifetime/path (php-src ext/session/session.c; #9982).
 */
class session_set_cookie_params_ extends Internal
{
    public function __construct()
    {
        parent::__construct('session_set_cookie_params');
    }

    public function execute(Frame $frame): void
    {
        $parsed = SessionCookieParams::parseSetArgs('session_set_cookie_params', $frame->calledArgs, $frame);
        $ok = VmSession::applyCookieParams($frame, $parsed);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSessionCookieAndPath::invokeSetCookieParams($context, ...$args);
    }
}
