<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** clearstatcache() — VM via host PHP stat cache; JIT/AOT no-op (libc stat has no cache). */
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
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
        if (0 === $argc) {
            \clearstatcache();

            return;
        }
        $clearRealpath = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $clearRealpath->type) {
            throw new \LogicException('clearstatcache() argument #1 must be a boolean in this compiler build');
        }
        if (1 === $argc) {
            \clearstatcache($clearRealpath->toBool());

            return;
        }
        $path = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $path->type) {
            throw new \LogicException('clearstatcache() argument #2 must be a string in this compiler build');
        }
        \clearstatcache($clearRealpath->toBool(), $path->toString());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \LogicException('clearstatcache() accepts at most two arguments in this compiler build');
        }
        if ($argc >= 1) {
            $this->jitBool($context, $args[0], 'clearstatcache() argument #1');
        }
        if (2 === $argc) {
            $this->jitString($context, $args[1], 'clearstatcache() argument #2');
        }

        return JitClearstatcache::invoke($context);
    }
}
