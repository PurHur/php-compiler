<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * output_reset_rewrite_vars() — clear mod_rewrite var table (ext/standard/url.c, #6031).
 */
final class output_reset_rewrite_vars extends Internal
{
    public function __construct()
    {
        parent::__construct('output_reset_rewrite_vars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'output_reset_rewrite_vars() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ResponseContext::resetRewriteVars());
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitOutputRewriteVars::reset($context, ...$args);
    }
}
