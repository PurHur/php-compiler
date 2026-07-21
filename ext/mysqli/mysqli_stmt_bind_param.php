<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_stmt_bind_param() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #21788). */
final class mysqli_stmt_bind_param extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_bind_param');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('mysqli_stmt_bind_param() expects at least 3 arguments, '.\count($frame->calledArgs).' given');
        }
        $stmt = VmMysqliStmt::requireStmtObject($frame->calledArgs[0], 'mysqli_stmt_bind_param');
        $types = $frame->calledArgs[1]->resolveIndirect()->toString();
        $refVars = [];
        for ($i = 2, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $refVars[] = $frame->calledArgs[$i];
        }
        $ok = VmMysqliStmt::bindParamNative(VmMysqliStmt::state($stmt), $types, $refVars);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_stmt_bind_param() is not implemented for JIT (issue #21788)');
    }
}
