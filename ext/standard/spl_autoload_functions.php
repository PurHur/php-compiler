<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * spl_autoload_functions() — list registered autoload callbacks (ext/spl/php_spl.c, #3534).
 */
final class spl_autoload_functions extends Internal
{
    public function __construct()
    {
        parent::__construct('spl_autoload_functions');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'spl_autoload_functions() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $frame->returnVar->array(VmSplAutoload::callbackSnapshot($ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'spl_autoload_functions() is not implemented for JIT in this compiler build (#3534)'
        );
    }
}
