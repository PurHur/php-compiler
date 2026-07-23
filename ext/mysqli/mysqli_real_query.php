<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_real_query() — php-src ext/mysqli/mysqli_api.c (#22249). */
final class mysqli_real_query extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_real_query');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_real_query() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_real_query', 2);
        $query = $frame->calledArgs[1]->resolveIndirect()->toString();
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_real_query() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::realQueryOnLink($obj, $ctx, $query));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_real_query() is not implemented for JIT (issue #22249)');
    }
}
