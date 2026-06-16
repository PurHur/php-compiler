<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** clearstatcache() — VM via VmStatCache; JIT/AOT via StatCacheRuntime (#9110). */
final class clearstatcache_ extends Internal
{
    public function __construct()
    {
        parent::__construct('clearstatcache');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \LogicException('clearstatcache() accepts at most two arguments in this compiler build');
        }
        if (0 === $argc) {
            VmStatCache::clear();
        } elseif (1 === $argc) {
            $clearRealpath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[0],
                'clearstatcache',
                0,
                'clear_realpath_cache'
            );
            VmStatCache::clear($clearRealpath);
        } else {
            $clearRealpath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[0],
                'clearstatcache',
                0,
                'clear_realpath_cache'
            );
            $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'clearstatcache', 1, 'filename');
            VmStatCache::clear($clearRealpath, $filename);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \LogicException('clearstatcache() accepts at most two arguments in this compiler build');
        }

        return JitClearstatcache::invoke($context, $argc, ...$args);
    }
}
