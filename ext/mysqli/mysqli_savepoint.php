<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** mysqli_savepoint() — php-src ext/mysqli/mysqli_api.c (#21825). */
final class mysqli_savepoint extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_savepoint');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_savepoint() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_savepoint', 2);
        $name = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[1], 'mysqli_savepoint', 1, 'name');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_savepoint() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::savepointOnLink($obj, $ctx, $name));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_savepoint() is not implemented for JIT (issue #21825)');
    }
}
