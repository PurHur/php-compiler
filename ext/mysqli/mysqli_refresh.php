<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_refresh() — php-src ext/mysqli/mysqli_api.c (#21827). */
final class mysqli_refresh extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_refresh');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_refresh() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_refresh', 2);
        $options = MysqliProceduralLink::optionalIntArg($frame, 1);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_refresh() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::refreshOnLink($obj, $ctx, $options));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_refresh() is not implemented for JIT (issue #21827)');
    }
}
