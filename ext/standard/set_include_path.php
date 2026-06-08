<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** set_include_path() — replace include_path (ext/standard/basic_functions.c; #3223). */
final class set_include_path extends Internal
{
    public function __construct()
    {
        parent::__construct('set_include_path');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'set_include_path() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $newPath = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'set_include_path',
            0,
            'new_include_path'
        );
        $old = VmIncludePath::push($newPath);
        $frame->returnVar->string($old);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'set_include_path() expects exactly 1 argument, '.\count($args).' given'
            );
        }
        $newPath = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'set_include_path',
            0,
            'new_include_path'
        );

        return JitIncludePath::set($context, $newPath);
    }
}
