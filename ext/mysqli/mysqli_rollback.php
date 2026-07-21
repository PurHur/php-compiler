<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_rollback() — php-src ext/mysqli/mysqli_api.c (#21825). */
final class mysqli_rollback extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_rollback');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_rollback');
        $flags = MysqliProceduralLink::optionalIntArg($frame, 1);
        $name = MysqliProceduralLink::optionalStringArg($frame, 2);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_rollback() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::rollbackOnLink($obj, $ctx, $flags, $name));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_rollback() is not implemented for JIT (issue #21825)');
    }
}
