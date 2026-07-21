<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_affected_rows() — php-src ext/mysqli/mysqli_api.c (#3435). */
final class mysqli_affected_rows extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_affected_rows');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_affected_rows() expects exactly 1 argument, 0 given');
        }
        $link = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $link->type) {
            throw new \TypeError('mysqli_affected_rows(): Argument #1 ($mysql) must be of type mysqli');
        }
        $native = VmMysqli::requireNative($link->toObject());
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($native->affected_rows);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_affected_rows() is not implemented for JIT (issue #3435)');
    }
}
