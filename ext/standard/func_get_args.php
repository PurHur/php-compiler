<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variadic;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** func_get_args() — arguments passed to the current user function (issue #197). VM only. */
final class func_get_args extends Internal
{
    public function __construct()
    {
        parent::__construct('func_get_args');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $visible = Variadic::visibleCallArgs($frame);
        $frame->returnVar->newArray();
        $ht = $frame->returnVar->toArray();
        foreach ($visible as $arg) {
            $copy = new Variable();
            $copy->copyFrom($arg);
            $ht->append($copy);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'func_get_args() not implemented for JIT in this compiler build (issue #197)'
        );
    }
}
