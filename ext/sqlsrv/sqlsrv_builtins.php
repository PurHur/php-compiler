<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlsrv;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

require_once __DIR__.'/VmSqlsrvCore.php';
require_once __DIR__.'/VmSqlsrvConnection.php';

/** Shared JIT stub for sqlsrv_* v1 (#6577). */
abstract class SqlsrvFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #6577)');
    }

    protected function requireConnection(Variable $var, string $fn, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d must be a valid sqlsrv connection resource, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    default => 'mixed',
                }
            ));
        }

        return VmSqlsrvConnection::requireLive($var->toObject(), $fn);
    }
}

/** sqlsrv_connect() — php-src ext/sqlsrv/php_sqlsrv.c (#6577). */
final class sqlsrv_connect extends SqlsrvFunction
{
    public function __construct()
    {
        parent::__construct('sqlsrv_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'sqlsrv_connect() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $serverName = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'sqlsrv_connect', 1, 'serverName');
        $connectionInfo = [];
        if (2 === $argc) {
            $connectionInfo = VmSqlsrvCore::coerceConnectionInfo($frame->calledArgs[1], 'sqlsrv_connect');
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('sqlsrv_connect() requires a VM context');
        $result = VmSqlsrvCore::connect($serverName, $connectionInfo, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }
}

/** sqlsrv_close() — php-src ext/sqlsrv (#6577). */
final class sqlsrv_close extends SqlsrvFunction
{
    public function __construct()
    {
        parent::__construct('sqlsrv_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'sqlsrv_close() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $this->requireConnection($frame->calledArgs[0], 'sqlsrv_close', 1);
        $frame->returnVar->bool(VmSqlsrvConnection::close($conn));
    }
}

/** sqlsrv_query() — php-src ext/sqlsrv (#6577). */
final class sqlsrv_query extends SqlsrvFunction
{
    public function __construct()
    {
        parent::__construct('sqlsrv_query');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'sqlsrv_query() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $this->requireConnection($frame->calledArgs[0], 'sqlsrv_query', 1);
        VmSqlsrvCore::clearErrors();
        VmSqlsrvCore::pushError('IMSSP', -49, 'This extension requires the Microsoft ODBC Driver for SQL Server to communicate with SQL Server');
        $frame->returnVar->bool(false);
    }
}

/** sqlsrv_fetch_array() — php-src ext/sqlsrv (#6577). */
final class sqlsrv_fetch_array extends SqlsrvFunction
{
    public function __construct()
    {
        parent::__construct('sqlsrv_fetch_array');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'sqlsrv_fetch_array() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(false);
    }
}

/** sqlsrv_errors() — php-src ext/sqlsrv (#6577). */
final class sqlsrv_errors extends SqlsrvFunction
{
    public function __construct()
    {
        parent::__construct('sqlsrv_errors');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'sqlsrv_errors() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (SqlsrvExtensionPolicy::hasNativeDriver() && 0 === $argc) {
            VmSqlsrvCore::importHostErrors();
        }
        VmSqlsrvCore::buildErrorsVariable($frame->returnVar);
    }
}
