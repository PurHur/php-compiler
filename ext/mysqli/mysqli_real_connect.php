<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_real_connect() — php-src ext/mysqli/mysqli_api.c (#21791). */
final class mysqli_real_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_real_connect');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_real_connect');
        $hostname = MysqliProceduralLink::optionalStringArg($frame, 1);
        $username = MysqliProceduralLink::optionalStringArg($frame, 2);
        $password = MysqliProceduralLink::optionalStringArg($frame, 3);
        $database = MysqliProceduralLink::optionalStringArg($frame, 4);
        $port = \count($frame->calledArgs) >= 6
            ? MysqliProceduralLink::optionalIntArg($frame, 5)
            : null;
        $socket = MysqliProceduralLink::optionalStringArg($frame, 6);
        $flags = MysqliProceduralLink::optionalIntArg($frame, 7);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_real_connect() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::realConnectOnLink(
            $obj,
            $ctx,
            $hostname,
            $username,
            $password,
            $database,
            $port,
            $socket,
            $flags
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_real_connect() is not implemented for JIT (issue #21791)');
    }
}
