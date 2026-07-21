<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_autocommit() — php-src ext/mysqli/mysqli_api.c (#21825). */
final class mysqli_autocommit extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_autocommit');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_autocommit', 2);
        $mode = MysqliProceduralLink::boolArg($frame, 1, 'mysqli_autocommit');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_autocommit() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::autocommitOnLink($obj, $ctx, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_autocommit() is not implemented for JIT (issue #21825)');
    }
}
