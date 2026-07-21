<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** mysqli_set_charset() — php-src ext/mysqli/mysqli_nonapi.c (#21791). */
final class mysqli_set_charset extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_set_charset');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_set_charset() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_set_charset', 2);
        $charset = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[1], 'mysqli_set_charset', 1, 'csname');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_set_charset() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::setCharsetOnLink($obj, $ctx, $charset));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_set_charset() is not implemented for JIT (issue #21791)');
    }
}
