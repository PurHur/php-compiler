<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_get_connection_stats() — php-src ext/mysqli/mysqli_api.c (#21827). */
final class mysqli_get_connection_stats extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_get_connection_stats');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_get_connection_stats');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_get_connection_stats() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqli::assignRow($frame->returnVar, VmMysqli::connectionStatsOnLink($obj, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_get_connection_stats() is not implemented for JIT (issue #21827)');
    }
}
