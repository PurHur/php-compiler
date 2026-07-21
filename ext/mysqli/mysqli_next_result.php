<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_next_result() — php-src ext/mysqli/mysqli_api.c (#21791). */
final class mysqli_next_result extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_next_result');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_next_result');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_next_result() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::nextResultOnLink($obj, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_next_result() is not implemented for JIT (issue #21791)');
    }
}
