<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * mysqli_stmt_init / mysqli_stmt_prepare two-step API (#22215).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c
 */

abstract class MysqliStmtInitPrepareBuiltin extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22215)');
    }
}

/** mysqli_stmt_init() — php-src ext/mysqli/mysqli_api.c (#22215). */
final class mysqli_stmt_init extends MysqliStmtInitPrepareBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_init');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_stmt_init');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqliStmt::initOnLink($obj);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

/** mysqli_stmt_prepare() — php-src ext/mysqli/mysqli_api.c (#22215). */
final class mysqli_stmt_prepare extends MysqliStmtInitPrepareBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_prepare');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'mysqli_stmt_prepare() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $stmt = VmMysqliStmt::requireStmtObject($frame->calledArgs[0], 'mysqli_stmt_prepare');
        $query = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[1], 'mysqli_stmt_prepare', 1, 'query');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqliStmt::prepareOnStmt($stmt, $query));
    }
}
