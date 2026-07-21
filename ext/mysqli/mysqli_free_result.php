<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_free_result() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #3435). */
final class mysqli_free_result extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_free_result');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_free_result() expects exactly 1 argument, 0 given');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('mysqli_free_result(): Argument #1 ($result) must be of type mysqli_result');
        }
        $obj = $var->toObject();
        $state = VmMysqliResult::state($obj);
        if (null !== $state->native) {
            $state->native->free();
            $state->native = null;
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_free_result() is not implemented for JIT (issue #3435)');
    }
}
