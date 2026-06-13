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
 * output_add_rewrite_var() — register mod_rewrite name/value pair (ext/standard/url.c, #6031).
 */
final class output_add_rewrite_var extends Internal
{
    public function __construct()
    {
        parent::__construct('output_add_rewrite_var');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'output_add_rewrite_var() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'output_add_rewrite_var',
            0,
            'name'
        );
        $value = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'output_add_rewrite_var',
            1,
            'value'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ResponseContext::addRewriteVar($name, $value));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitOutputRewriteVars::add($context, ...$args);
    }
}
