<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Multi-query / unbuffered result API (#22184).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c
 *   mysqli_use_result, mysqli_more_results,
 *   mysqli_stmt_more_results, mysqli_stmt_next_result
 */

final class mysqli_use_result extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_use_result');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_use_result');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_use_result() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqli::useResultOnLink($obj, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_use_result() is not implemented for JIT (issue #22184)');
    }
}

final class mysqli_more_results extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_more_results');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_more_results');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_more_results() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::moreResultsOnLink($obj, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_more_results() is not implemented for JIT (issue #22184)');
    }
}

final class mysqli_stmt_more_results extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_more_results');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_stmt_more_results() expects exactly 1 argument, 0 given');
        }
        $stmt = VmMysqliStmt::requireStmtObject($frame->calledArgs[0], 'mysqli_stmt_more_results');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqliStmt::moreResults($stmt));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_stmt_more_results() is not implemented for JIT (issue #22184)');
    }
}

final class mysqli_stmt_next_result extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_next_result');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_stmt_next_result() expects exactly 1 argument, 0 given');
        }
        $stmt = VmMysqliStmt::requireStmtObject($frame->calledArgs[0], 'mysqli_stmt_next_result');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqliStmt::nextResult($stmt));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_stmt_next_result() is not implemented for JIT (issue #22184)');
    }
}
