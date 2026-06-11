<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPLLVM\Value;

/** get_include_path() — active include_path (ext/standard/basic_functions.c; #3223). */
final class get_include_path extends Internal
{
    public function __construct()
    {
        parent::__construct('get_include_path');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'get_include_path() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        BuiltinExecute::writeReturn(
            $frame,
            static fn ($ret) => $ret->string(VmIncludePath::get())
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(
                'get_include_path() expects exactly 0 arguments, '.\count($args).' given'
            );
        }

        return JitIncludePath::get($context);
    }
}
