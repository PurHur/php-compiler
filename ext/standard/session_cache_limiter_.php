<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * session_cache_limiter() — session cache control header mode (php-src ext/session/session.c; #11095).
 *
 * Stub `?string $value = null`: omitted or null → getter; non-null → setter (#30396).
 */
class session_cache_limiter_ extends Internal
{
    public function __construct()
    {
        parent::__construct('session_cache_limiter');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'session_cache_limiter() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            if (1 === $argc && !$this->isNullArg($frame)) {
                $newLimiter = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[0],
                    'session_cache_limiter',
                    1,
                    'value'
                );
                VmSession::setCacheLimiter($frame, $newLimiter);
            }

            return;
        }
        if (1 === $argc) {
            // php-src ZEND_PARSE_PARAMETERS optional string: null/absent → get (#30396).
            if ($this->isNullArg($frame)) {
                $frame->returnVar->string(VmSession::getCacheLimiter());

                return;
            }
            $newLimiter = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[0],
                'session_cache_limiter',
                1,
                'value'
            );
            $previous = VmSession::setCacheLimiter($frame, $newLimiter);
            if (false === $previous) {
                $frame->returnVar->bool(false);

                return;
            }
            $frame->returnVar->string($previous);

            return;
        }
        $frame->returnVar->string(VmSession::getCacheLimiter());
    }

    private function isNullArg(Frame $frame): bool
    {
        return Variable::TYPE_NULL === $frame->calledArgs[0]->resolveIndirect()->type;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSessionCookieAndPath::invokeCacheLimiter($context, ...$args);
    }
}
