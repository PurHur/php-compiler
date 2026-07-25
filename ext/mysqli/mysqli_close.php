<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_close() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #3435). */
final class mysqli_close extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_close');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_close() expects exactly 1 argument, 0 given');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('mysqli_close(): Argument #1 ($mysql) must be of type mysqli');
        }
        $obj = $var->toObject();
        $state = VmMysqli::state($obj);
        if (null !== $state->native) {
            $state->native->close();
            $state->native = null;
        }
        VmMysqli::noteLinkClosed($state);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_close() is not implemented for JIT (issue #3435)');
    }
}
