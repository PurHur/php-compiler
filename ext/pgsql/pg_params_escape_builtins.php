<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * pg_query_params / prepare / execute / escape_* / fetch_all / fetch_all_columns / affected_rows / num_fields (#20661, #22216).
 * Loaded via Module::getFunctions() + spine require.
 */

final class pg_query_params extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_query_params');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_query_params() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_query_params', 1);
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_query_params', 1, 'query');
        $params = self::coerceParamList($frame->calledArgs[2], 'pg_query_params', 2);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_query_params() requires a VM context');
        }
        $result = VmPgsqlCore::queryParams($conn, $query, $params, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_query_params() is not implemented for JIT (#20661)');
    }

    /**
     * @return list<string|null>
     */
    public static function coerceParamList(Variable $var, string $fn, int $argIndex): array
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($params) must be of type array, %s given',
                $fn,
                $argIndex + 1,
                match ($resolved->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }
        $out = [];
        foreach ($resolved->toArray()->iterateKeyed(true) as [, $valueVar]) {
            $v = $valueVar->resolveIndirect();
            if (Variable::TYPE_NULL === $v->type) {
                $out[] = null;
            } elseif (Variable::TYPE_STRING === $v->type) {
                $out[] = $v->toString();
            } elseif (Variable::TYPE_INTEGER === $v->type) {
                $out[] = (string) $v->toInt();
            } elseif (Variable::TYPE_FLOAT === $v->type) {
                $out[] = (string) $v->toFloat();
            } elseif (Variable::TYPE_BOOLEAN === $v->type) {
                $out[] = $v->toBool() ? '1' : '0';
            } else {
                $out[] = VmString::coerceStringBuiltinArg($valueVar, $fn, $argIndex, 'params');
            }
        }

        return $out;
    }
}

final class pg_prepare extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_prepare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_prepare() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_prepare', 1);
        $stmt = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_prepare', 1, 'statement_name');
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pg_prepare', 2, 'query');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_prepare() requires a VM context');
        }
        $result = VmPgsqlCore::prepare($conn, $stmt, $query, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_prepare() is not implemented for JIT (#20661)');
    }
}

final class pg_execute extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_execute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_execute() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_execute', 1);
        $stmt = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_execute', 1, 'statement_name');
        $params = pg_query_params::coerceParamList($frame->calledArgs[2], 'pg_execute', 2);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_execute() requires a VM context');
        }
        $result = VmPgsqlCore::executePrepared($conn, $stmt, $params, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_execute() is not implemented for JIT (#20661)');
    }
}

final class pg_escape_string extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_escape_string');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_escape_string() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            // php-src FETCH_DEFAULT_LINK — E_DEPRECATED then PQescapeString{,Conn} (#31184).
            $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_escape_string', 0, 'data');
            $connObj = VmPgsqlConnection::fetchDefaultLinkDeprecated($frame, 'pg_escape_string');
            if (null === $connObj) {
                $frame->returnVar->string(VmPgsqlNative::escapeString($data));

                return;
            }
            $esc = VmPgsqlNative::escapeStringConn(VmPgsqlConnection::native($connObj), $data);
            $frame->returnVar->string(false === $esc ? '' : $esc);

            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_escape_string', 1);
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_escape_string', 1, 'data');
        $esc = VmPgsqlNative::escapeStringConn(VmPgsqlConnection::native($conn), $data);
        $frame->returnVar->string(false === $esc ? '' : $esc);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_escape_string() is not implemented for JIT (#20661)');
    }
}

final class pg_escape_literal extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_escape_literal');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_escape_literal() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31184).
            $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_escape_literal', 0, 'data');
            $connObj = VmPgsqlConnection::fetchDefaultLinkDeprecated($frame, 'pg_escape_literal');
            if (null === $connObj) {
                throw new \Error('No PostgreSQL connection opened yet');
            }
            $frame->returnVar->string(VmPgsqlNative::escapeLiteral(VmPgsqlConnection::native($connObj), $data));

            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_escape_literal', 1);
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_escape_literal', 1, 'data');
        $frame->returnVar->string(VmPgsqlNative::escapeLiteral(VmPgsqlConnection::native($conn), $data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_escape_literal() is not implemented for JIT (#20661)');
    }
}

final class pg_escape_identifier extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_escape_identifier');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_escape_identifier() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31184).
            $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_escape_identifier', 0, 'data');
            $connObj = VmPgsqlConnection::fetchDefaultLinkDeprecated($frame, 'pg_escape_identifier');
            if (null === $connObj) {
                throw new \Error('No PostgreSQL connection opened yet');
            }
            $frame->returnVar->string(VmPgsqlNative::escapeIdentifier(VmPgsqlConnection::native($connObj), $data));

            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_escape_identifier', 1);
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_escape_identifier', 1, 'data');
        $frame->returnVar->string(VmPgsqlNative::escapeIdentifier(VmPgsqlConnection::native($conn), $data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_escape_identifier() is not implemented for JIT (#20661)');
    }
}

final class pg_escape_bytea extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_escape_bytea');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_escape_bytea() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            // php-src FETCH_DEFAULT_LINK — E_DEPRECATED then PQescapeBytea{,Conn} (#31184).
            $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_escape_bytea', 0, 'data');
            $connObj = VmPgsqlConnection::fetchDefaultLinkDeprecated($frame, 'pg_escape_bytea');
            if (null === $connObj) {
                $frame->returnVar->string(VmPgsqlNative::escapeBytea($data));

                return;
            }
            $frame->returnVar->string(VmPgsqlNative::escapeByteaConn(VmPgsqlConnection::native($connObj), $data));

            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_escape_bytea', 1);
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_escape_bytea', 1, 'data');
        $frame->returnVar->string(VmPgsqlNative::escapeByteaConn(VmPgsqlConnection::native($conn), $data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_escape_bytea() is not implemented for JIT (#20661)');
    }
}

final class pg_unescape_bytea extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_unescape_bytea');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_unescape_bytea() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_unescape_bytea', 0, 'data');
        $frame->returnVar->string(VmPgsqlNative::unescapeBytea($data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_unescape_bytea() is not implemented for JIT (#20661)');
    }
}

final class pg_affected_rows extends Internal
{
    public function __construct(string $name = 'pg_affected_rows')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_affected_rows() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_affected_rows', 1);
        $frame->returnVar->int(VmPgsqlNative::cmdTuples(VmPgsqlResult::native($result)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_affected_rows() is not implemented for JIT (#20661)');
    }
}

final class pg_fetch_all extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_fetch_all');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_fetch_all() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_fetch_all', 1);
        $mode = PgsqlConstants::PGSQL_ASSOC;
        if ($argc >= 2) {
            $mode = $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        $frame->returnVar->array(VmPgsqlCore::fetchAll($result, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_fetch_all() is not implemented for JIT (#20661)');
    }
}

/**
 * pg_fetch_all_columns (php-src ext/pgsql/pgsql.c; #22216).
 */
final class pg_fetch_all_columns extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_fetch_all_columns');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_fetch_all_columns() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_fetch_all_columns', 1);
        $field = 0;
        if ($argc >= 2) {
            $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        $frame->returnVar->array(VmPgsqlCore::fetchAllColumns($result, $field));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_fetch_all_columns() is not implemented for JIT (#22216)');
    }
}

final class pg_num_fields extends Internal
{
    public function __construct(string $name = 'pg_num_fields')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_num_fields() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_num_fields', 1);
        $frame->returnVar->int(VmPgsqlNative::nfields(VmPgsqlResult::native($result)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_num_fields() is not implemented for JIT (#20661)');
    }
}
