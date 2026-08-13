<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * session_save_path() — get/set session file save directory (php-src ext/session/session.c; #3418).
 */
class session_save_path_ extends Internal
{
    public function __construct()
    {
        parent::__construct('session_save_path');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'session_save_path() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (0 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->string(VmSession::getSavePath());
            }

            return;
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        $newPath = VmString::coerceNullableStringBuiltinArg($pathVar, 'session_save_path', 0, 'path');
        if (null === $newPath) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->string(VmSession::getSavePath());
            }

            return;
        }
        $previous = VmSession::setSavePath($frame, $newPath);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $previous) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($previous);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSessionCookieAndPath::invokeSavePath($context, ...$args);
    }
}
