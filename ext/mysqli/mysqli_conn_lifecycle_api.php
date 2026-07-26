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
 * Procedural mysqli connection lifecycle / introspection helpers (#22174).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c / mysqli_nonapi.c
 * — mysqli_ping, mysqli_select_db, mysqli_change_user, mysqli_thread_id,
 *   mysqli_kill, mysqli_get_client_stats
 */

abstract class MysqliConnLifecycleBuiltin extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22174)');
    }
}

/** mysqli_ping() — php-src ext/mysqli/mysqli_api.c (#22174). */
final class mysqli_ping extends MysqliConnLifecycleBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_ping');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_ping');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_ping() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::pingOnLink($obj, $ctx));
    }
}

/** mysqli_select_db() — php-src ext/mysqli/mysqli_api.c (#22174). */
final class mysqli_select_db extends MysqliConnLifecycleBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_select_db');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_select_db() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_select_db', 2);
        $database = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[1], 'mysqli_select_db', 1, 'database');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_select_db() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::selectDbOnLink($obj, $ctx, $database));
    }
}

/** mysqli_change_user() — php-src ext/mysqli/mysqli_api.c (#22174). */
final class mysqli_change_user extends MysqliConnLifecycleBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_change_user');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError('mysqli_change_user() expects exactly 4 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_change_user', 4);
        $username = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[1], 'mysqli_change_user', 1, 'username');
        $password = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[2], 'mysqli_change_user', 2, 'password');
        $database = MysqliProceduralLink::optionalStringArg($frame, 3);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_change_user() requires VM context');
        MysqliProceduralLink::setBoolReturn(
            $frame,
            VmMysqli::changeUserOnLink($obj, $ctx, $username, $password, $database)
        );
    }
}

/** mysqli_thread_id() — php-src ext/mysqli/mysqli_api.c (#22174). */
final class mysqli_thread_id extends MysqliConnLifecycleBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_thread_id');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_thread_id');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_thread_id() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::threadIdOnLink($obj, $ctx));
        }
    }
}

/** mysqli_kill() — php-src ext/mysqli/mysqli_api.c (#22174). */
final class mysqli_kill extends MysqliConnLifecycleBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_kill');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_kill() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_kill', 2);
        $processId = MysqliProceduralLink::requireIntArg($frame, 1, 'mysqli_kill', 'process_id');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_kill() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::killOnLink($obj, $ctx, $processId));
    }
}

/**
 * mysqli_get_client_stats() — php-src ext/mysqli/mysqli_nonapi.c (#22174).
 *
 * Connectionless mysqlnd client stats aggregate.
 */
final class mysqli_get_client_stats extends MysqliConnLifecycleBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_client_stats');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'mysqli_get_client_stats', 0);
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqli::assignRow($frame->returnVar, VmMysqli::clientStats());
    }
}
