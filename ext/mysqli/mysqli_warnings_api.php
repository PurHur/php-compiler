<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mysqli_get_warnings / mysqli_stmt_get_warnings (#22224).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_nonapi.c + mysqli_warning.c
 */

abstract class MysqliWarningsBuiltin extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22224)');
    }
}

/** mysqli_get_warnings() — php-src ext/mysqli/mysqli_nonapi.c (#22224). */
final class mysqli_get_warnings extends MysqliWarningsBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_warnings');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_get_warnings');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_get_warnings() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $warning = VmMysqli::getWarningsOnLink($obj, $ctx);
        if (null === $warning) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($warning);
        }
    }
}

/** mysqli_stmt_get_warnings() — php-src ext/mysqli/mysqli_nonapi.c (#22224). */
final class mysqli_stmt_get_warnings extends MysqliWarningsBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_get_warnings');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_stmt_get_warnings() expects exactly 1 argument, 0 given');
        }
        $stmt = VmMysqliStmt::requireStmtObject($frame->calledArgs[0], 'mysqli_stmt_get_warnings');
        if (null === $frame->returnVar) {
            return;
        }
        $warning = VmMysqliStmt::getWarnings($stmt);
        if (null === $warning) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($warning);
        }
    }
}
