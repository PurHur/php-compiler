<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_stmt_execute() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #21788). */
final class mysqli_stmt_execute extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_execute');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_stmt_execute() expects at least 1 argument, 0 given');
        }
        $stmt = VmMysqliStmt::requireStmtObject($frame->calledArgs[0], 'mysqli_stmt_execute');
        $state = VmMysqliStmt::state($stmt);
        VmMysqliStmt::syncBindParamProxiesFromVm($state);
        $ok = VmMysqliStmt::requireNative($stmt)->execute();
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_stmt_execute() is not implemented for JIT (issue #21788)');
    }
}
