<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_stat() — php-src ext/mysqli/mysqli_api.c (#21791). */
final class mysqli_stat extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_stat');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_stat');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_stat() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $stat = VmMysqli::statOnLink($obj, $ctx);
        if (null === $stat) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($stat);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_stat() is not implemented for JIT (issue #21791)');
    }
}
