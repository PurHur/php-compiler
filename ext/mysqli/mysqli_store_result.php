<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_store_result() — php-src ext/mysqli/mysqli_api.c (#21791). */
final class mysqli_store_result extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_store_result');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_store_result');
        $flags = MysqliProceduralLink::optionalIntArg($frame, 1);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_store_result() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqli::storeResultOnLink($obj, $ctx, $flags);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_store_result() is not implemented for JIT (issue #21791)');
    }
}
