<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_info() — php-src ext/mysqli/mysqli_api.c (#21791). */
final class mysqli_info extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_info');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_info');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_info() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $info = VmMysqli::infoOnLink($obj, $ctx);
        if (null === $info) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($info);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_info() is not implemented for JIT (issue #21791)');
    }
}
