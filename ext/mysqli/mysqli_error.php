<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_error() — php-src ext/mysqli/mysqli_api.c (#3435). */
final class mysqli_error extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_error');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_error() expects exactly 1 argument, 0 given');
        }
        $link = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $link->type) {
            throw new \TypeError('mysqli_error(): Argument #1 ($mysql) must be of type mysqli');
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_error() requires VM context');
        $native = VmMysqli::requireNative($link->toObject(), $ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($native->error);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_error() is not implemented for JIT (issue #3435)');
    }
}
