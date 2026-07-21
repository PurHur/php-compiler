<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_stmt_close() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #21788). */
final class mysqli_stmt_close extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_close');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_stmt_close() expects at least 1 argument, 0 given');
        }
        $stmt = VmMysqliStmt::requireStmtObject($frame->calledArgs[0], 'mysqli_stmt_close');
        $state = VmMysqliStmt::state($stmt);
        if (null !== $state->native) {
            $state->native->close();
            $state->native = null;
        }
        VmMysqliStmt::destroyState($stmt);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_stmt_close() is not implemented for JIT (issue #21788)');
    }
}
