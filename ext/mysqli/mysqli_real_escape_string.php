<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_real_escape_string() / mysqli_escape_string() — php-src ext/mysqli/mysqli_api.c (#3435). */
final class mysqli_real_escape_string extends Internal
{
    public function __construct(string $name = 'mysqli_real_escape_string')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_real_escape_string() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $link = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $link->type) {
            throw new \TypeError('mysqli_real_escape_string(): Argument #1 ($mysql) must be of type mysqli');
        }
        $str = $frame->calledArgs[1]->resolveIndirect()->toString();
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_real_escape_string() requires VM context');
        $native = VmMysqli::requireNative($link->toObject(), $ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($native->real_escape_string($str));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_real_escape_string() is not implemented for JIT (issue #3435)');
    }
}
