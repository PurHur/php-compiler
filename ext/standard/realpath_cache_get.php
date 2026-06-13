<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** realpath_cache_get() — snapshot realpath cache entries (#3463, ext/standard/url.c). */
final class realpath_cache_get extends Internal
{
    public function __construct()
    {
        parent::__construct('realpath_cache_get');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'realpath_cache_get() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmRealpathCache::get());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(
                'realpath_cache_get() expects exactly 0 arguments, '.\count($args).' given'
            );
        }

        return JitRealpathCache::get($context);
    }
}
