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
 * session_cache_expire() — session cache lifetime in minutes (php-src ext/session/session.c; #14613).
 */
class session_cache_expire_ extends Internal
{
    public function __construct()
    {
        parent::__construct('session_cache_expire');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'session_cache_expire() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (1 === $argc && Variable::TYPE_NULL !== $frame->calledArgs[0]->resolveIndirect()->type) {
            $minutes = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                0,
                'session_cache_expire',
                1,
                'value'
            );
            VmSession::setCacheExpire($minutes);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmSession::getCacheExpire());
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                'session_cache_expire() expects at most 1 argument, '.\count($args).' given'
            );
        }

        return JitSessionCacheExpire::invoke($context, ...$args);
    }
}
