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
 * clearstatcache() — VM via host PHP; JIT/AOT deferred (libc stat has no PHP cache, issue #1196).
 */
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
            \clearstatcache();
        } elseif (1 === $argc) {
            $clearRealpath = $frame->calledArgs[0]->resolveIndirect()->toBool();
            \clearstatcache($clearRealpath);
        } else {
            $clearRealpath = $frame->calledArgs[0]->resolveIndirect()->toBool();
            $filename = VmReflection::stringArg($frame->calledArgs[1], 'clearstatcache() filename');
            \clearstatcache($clearRealpath, $filename);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'clearstatcache() is not implemented for JIT/AOT in this compiler build; '
            .'JIT stat builtins use libc stat(2) directly (no PHP stat cache)'
        );
    }
}
