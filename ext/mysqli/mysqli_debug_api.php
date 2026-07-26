<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * mysqli_dump_debug_info / mysqli_debug (#22223).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli.c
 */

abstract class MysqliDebugBuiltin extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22223)');
    }
}

/** mysqli_dump_debug_info() — php-src ext/mysqli/mysqli.c (#22223). */
final class mysqli_dump_debug_info extends MysqliDebugBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_dump_debug_info');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_dump_debug_info');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_dump_debug_info() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::dumpDebugInfoOnLink($obj, $ctx));
    }
}

/**
 * mysqli_debug() — php-src ext/mysqli/mysqli.c (#22223).
 *
 * Connectionless mysqlnd debug option setter; always returns true when available.
 */
final class mysqli_debug extends MysqliDebugBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_debug');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'mysqli_debug', 1);
        $options = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[0], 'mysqli_debug', 0, 'options');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::debugOptions($options));
    }
}
