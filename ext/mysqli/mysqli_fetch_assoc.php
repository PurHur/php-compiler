<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_fetch_assoc() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #3435). */
final class mysqli_fetch_assoc extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_assoc');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_fetch_assoc() expects exactly 1 argument, 0 given');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('mysqli_fetch_assoc(): Argument #1 ($result) must be of type mysqli_result');
        }
        $native = VmMysqliResult::requireNative($var->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        $row = $native->fetch_assoc();
        if (null === $row) {
            $frame->returnVar->null();
        } else {
            VmMysqli::assignRow($frame->returnVar, $row);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_fetch_assoc() is not implemented for JIT (issue #3435)');
    }
}
