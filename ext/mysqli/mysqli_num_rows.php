<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_num_rows() — php-src ext/mysqli/mysqli_api.c (#3435). */
final class mysqli_num_rows extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_num_rows');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_num_rows() expects exactly 1 argument, 0 given');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('mysqli_num_rows(): Argument #1 ($result) must be of type mysqli_result');
        }
        $native = VmMysqliResult::requireNative($var->toObject());
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($native->num_rows);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_num_rows() is not implemented for JIT (issue #3435)');
    }
}
