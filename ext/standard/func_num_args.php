<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variadic;
use PHPLLVM\Value;

/** func_num_args() — count of arguments passed to the current user function (issue #197). VM only. */
final class func_num_args extends Internal
{
    public function __construct()
    {
        parent::__construct('func_num_args');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(count(Variadic::visibleCallArgs($frame)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'func_num_args() not implemented for JIT in this compiler build (issue #197)'
        );
    }
}
