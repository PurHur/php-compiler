<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** restore_include_path() — pop include_path stack (ext/standard/basic_functions.c; #3223). */
final class restore_include_path extends Internal
{
    public function __construct()
    {
        parent::__construct('restore_include_path');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'restore_include_path() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        VmIncludePath::restore();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(
                'restore_include_path() expects exactly 0 arguments, '.\count($args).' given'
            );
        }
        JitIncludePath::restore($context);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
