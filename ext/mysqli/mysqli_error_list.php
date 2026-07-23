<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mysqli_error_list() / mysqli_stmt_error_list() — php-src ext/mysqli/mysqli_nonapi.c (#22225).
 *
 * Each row is assoc {errno, sqlstate, error}. Empty / uninitialized → [].
 */
final class mysqli_error_list extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_error_list');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_error_list');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_error_list() requires VM context');
        if (null !== $frame->returnVar) {
            VmMysqliResult::assignRows($frame->returnVar, VmMysqli::errorListOnLink($obj, $ctx));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_error_list() is not implemented for JIT (issue #22225)');
    }
}

/** mysqli_stmt_error_list() — php-src ext/mysqli/mysqli_nonapi.c (#22225). */
final class mysqli_stmt_error_list extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_error_list');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_stmt_error_list() expects exactly 1 argument, 0 given');
        }
        $stmt = VmMysqliStmt::requireStmtObject($frame->calledArgs[0], 'mysqli_stmt_error_list');
        if (null !== $frame->returnVar) {
            VmMysqliResult::assignRows($frame->returnVar, VmMysqliStmt::errorList($stmt));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_stmt_error_list() is not implemented for JIT (issue #22225)');
    }
}
