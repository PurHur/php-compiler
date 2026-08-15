<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

require_once __DIR__.'/PgSendAsyncReturn.php';

/**
 * pg_socket / pg_consume_input / pg_flush / pg_connect_poll (php-src ext/pgsql; #20636, #21896).
 * Loaded via Module::getFunctions() + spine require.
 */

/**
 * pg_connect_poll — PQconnectPoll (php-src ext/pgsql/pgsql.c; #21896).
 */
final class pg_connect_poll extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_connect_poll');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_connect_poll() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_connect_poll', 1);
        $frame->returnVar->int(VmPgsqlCore::connectPoll($conn));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_connect_poll() is not implemented for JIT (#21896)');
    }
}

final class pg_socket extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_socket');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_socket() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_socket', 1);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_socket() requires a VM context');
        }
        $sock = VmPgsqlCore::socket($conn, $ctx);
        if (false === $sock) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($sock);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_socket() is not implemented for JIT (#20636)');
    }
}

final class pg_consume_input extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_consume_input');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_consume_input() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_consume_input', 1);
        $frame->returnVar->bool(VmPgsqlCore::consumeInput($conn));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_consume_input() is not implemented for JIT (#20636)');
    }
}

final class pg_flush extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_flush');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_flush() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_flush', 1);
        $out = VmPgsqlCore::flush($conn);
        if (true === $out) {
            $frame->returnVar->bool(true);

            return;
        }
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_flush() is not implemented for JIT (#20636)');
    }
}

/**
 * pg_set_error_verbosity (php-src ext/pgsql/pgsql.c; #20660).
 */
final class pg_set_error_verbosity extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_set_error_verbosity');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_set_error_verbosity() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_set_error_verbosity', 1);
        $verbosityVar = $frame->calledArgs[1]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $verbosityVar->type
            && \PHPCompiler\VM\Variable::TYPE_FLOAT !== $verbosityVar->type
            && \PHPCompiler\VM\Variable::TYPE_BOOLEAN !== $verbosityVar->type
            && \PHPCompiler\VM\Variable::TYPE_STRING !== $verbosityVar->type
        ) {
            throw new \TypeError(\sprintf(
                'pg_set_error_verbosity(): Argument #2 ($verbosity) must be of type int, %s given',
                match ($verbosityVar->type) {
                    \PHPCompiler\VM\Variable::TYPE_NULL => 'null',
                    \PHPCompiler\VM\Variable::TYPE_ARRAY => 'array',
                    \PHPCompiler\VM\Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }
        $verbosity = (int) $verbosityVar->toInt();
        $prev = VmPgsqlNative::setErrorVerbosity(VmPgsqlConnection::native($conn), $verbosity);
        $frame->returnVar->int($prev);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_set_error_verbosity() is not implemented for JIT (#20660)');
    }
}

/**
 * pg_put_line (php-src ext/pgsql/pgsql.c; #20673).
 * 1-arg form uses the default link; 2-arg form takes Connection + data.
 */
final class pg_put_line extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_put_line');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_put_line() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_put_line', 1, 'query');
            // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31221).
            $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated(null, $frame, 'pg_put_line');
        } else {
            $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_put_line', 1);
            $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_put_line', 2, 'query');
        }
        $native = VmPgsqlConnection::native($connObj);
        $ok = VmPgsqlNative::putLine($native, $data);
        if (!$ok) {
            $msg = VmPgsqlNative::errorMessage($native);
            VmPgsqlConnection::setLastError($msg);
            @\trigger_error('pg_put_line(): Query failed: '.$msg, \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_put_line() is not implemented for JIT (#20673)');
    }
}

/**
 * pg_end_copy (php-src ext/pgsql/pgsql.c; #20673).
 */
final class pg_end_copy extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_end_copy');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'pg_end_copy() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $provided = null;
        if (1 === $argc) {
            $provided = VmPgsqlArg::optionalConnection($frame->calledArgs[0], 'pg_end_copy', 1);
        }
        // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31221).
        $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated($provided, $frame, 'pg_end_copy');
        $native = VmPgsqlConnection::native($connObj);
        $ok = VmPgsqlNative::endCopy($native);
        if (!$ok) {
            $msg = VmPgsqlNative::errorMessage($native);
            VmPgsqlConnection::setLastError($msg);
            @\trigger_error('pg_end_copy(): Query failed: '.$msg, \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_end_copy() is not implemented for JIT (#20673)');
    }
}

/**
 * pg_set_error_context_visibility (php-src ext/pgsql/pgsql.c; #20674).
 */
final class pg_set_error_context_visibility extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_set_error_context_visibility');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_set_error_context_visibility() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_set_error_context_visibility', 1);
        $visibilityVar = $frame->calledArgs[1]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $visibilityVar->type
            && \PHPCompiler\VM\Variable::TYPE_FLOAT !== $visibilityVar->type
            && \PHPCompiler\VM\Variable::TYPE_BOOLEAN !== $visibilityVar->type
            && \PHPCompiler\VM\Variable::TYPE_STRING !== $visibilityVar->type
        ) {
            throw new \TypeError(\sprintf(
                'pg_set_error_context_visibility(): Argument #2 ($visibility) must be of type int, %s given',
                match ($visibilityVar->type) {
                    \PHPCompiler\VM\Variable::TYPE_NULL => 'null',
                    \PHPCompiler\VM\Variable::TYPE_ARRAY => 'array',
                    \PHPCompiler\VM\Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }
        $visibility = (int) $visibilityVar->toInt();
        // php-src: visibility == NEVER || visibility & (ERRORS|ALWAYS)
        if (PgsqlConstants::PGSQL_SHOW_CONTEXT_NEVER !== $visibility
            && 0 === ($visibility & (PgsqlConstants::PGSQL_SHOW_CONTEXT_ERRORS | PgsqlConstants::PGSQL_SHOW_CONTEXT_ALWAYS))
        ) {
            throw new \ValueError(
                'pg_set_error_context_visibility(): Argument #2 ($visibility) must be one of PGSQL_SHOW_CONTEXT_NEVER, PGSQL_SHOW_CONTEXT_ERRORS or PGSQL_SHOW_CONTEXT_ALWAYS'
            );
        }
        $prev = VmPgsqlNative::setErrorContextVisibility(VmPgsqlConnection::native($conn), $visibility);
        $frame->returnVar->int($prev);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_set_error_context_visibility() is not implemented for JIT (#20674)');
    }
}

/**
 * Shared true / 0 / false return for pg_send_* (#20681).
 * Helper lives in {@see PgSendAsyncReturn.php} (own spine unit).
 */

/**
 * pg_send_query (php-src ext/pgsql/pgsql.c; #20681).
 */
final class pg_send_query extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_send_query');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_send_query() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_send_query', 1);
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_send_query', 1, 'query');
        PgSendAsyncReturn::assign($frame->returnVar, VmPgsqlCore::sendQuery($conn, $query));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_send_query() is not implemented for JIT (#20681)');
    }
}

/**
 * pg_send_query_params (php-src ext/pgsql/pgsql.c; #20681).
 */
final class pg_send_query_params extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_send_query_params');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_send_query_params() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_send_query_params', 1);
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_send_query_params', 1, 'query');
        $params = pg_query_params::coerceParamList($frame->calledArgs[2], 'pg_send_query_params', 2);
        PgSendAsyncReturn::assign($frame->returnVar, VmPgsqlCore::sendQueryParams($conn, $query, $params));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_send_query_params() is not implemented for JIT (#20681)');
    }
}

/**
 * pg_send_prepare (php-src ext/pgsql/pgsql.c; #20681).
 */
final class pg_send_prepare extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_send_prepare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_send_prepare() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_send_prepare', 1);
        $stmt = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_send_prepare', 1, 'statement_name');
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pg_send_prepare', 2, 'query');
        PgSendAsyncReturn::assign($frame->returnVar, VmPgsqlCore::sendPrepare($conn, $stmt, $query));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_send_prepare() is not implemented for JIT (#20681)');
    }
}

/**
 * pg_send_execute (php-src ext/pgsql/pgsql.c; #20681).
 */
final class pg_send_execute extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_send_execute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_send_execute() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_send_execute', 1);
        $stmt = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_send_execute', 1, 'statement_name');
        $params = pg_query_params::coerceParamList($frame->calledArgs[2], 'pg_send_execute', 2);
        PgSendAsyncReturn::assign($frame->returnVar, VmPgsqlCore::sendExecute($conn, $stmt, $params));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_send_execute() is not implemented for JIT (#20681)');
    }
}

/**
 * pg_get_result (php-src ext/pgsql/pgsql.c; #20681).
 */
final class pg_get_result extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_get_result');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_get_result() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_get_result', 1);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_get_result() requires a VM context');
        }
        $result = VmPgsqlCore::getResult($conn, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_get_result() is not implemented for JIT (#20681)');
    }
}

/**
 * pg_cancel_query (php-src ext/pgsql/pgsql.c; #20681).
 */
final class pg_cancel_query extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_cancel_query');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_cancel_query() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_cancel_query', 1);
        $frame->returnVar->bool(VmPgsqlCore::cancelQuery($conn));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_cancel_query() is not implemented for JIT (#20681)');
    }
}

/**
 * pg_get_notify (php-src ext/pgsql/pgsql.c; #20681).
 */
final class pg_get_notify extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_get_notify');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_get_notify() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_get_notify', 1);
        $mode = PgsqlConstants::PGSQL_ASSOC;
        if (2 === $argc) {
            $modeVar = $frame->calledArgs[1]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $modeVar->type
                && \PHPCompiler\VM\Variable::TYPE_FLOAT !== $modeVar->type
                && \PHPCompiler\VM\Variable::TYPE_BOOLEAN !== $modeVar->type
                && \PHPCompiler\VM\Variable::TYPE_STRING !== $modeVar->type
            ) {
                throw new \TypeError(\sprintf(
                    'pg_get_notify(): Argument #2 ($mode) must be of type int, %s given',
                    match ($modeVar->type) {
                        \PHPCompiler\VM\Variable::TYPE_NULL => 'null',
                        \PHPCompiler\VM\Variable::TYPE_ARRAY => 'array',
                        \PHPCompiler\VM\Variable::TYPE_OBJECT => 'object',
                        default => 'mixed',
                    }
                ));
            }
            $mode = (int) $modeVar->toInt();
        }
        if (0 === ($mode & PgsqlConstants::PGSQL_BOTH)) {
            throw new \ValueError(
                'pg_get_notify(): Argument #2 ($mode) must be one of PGSQL_ASSOC, PGSQL_NUM, or PGSQL_BOTH'
            );
        }
        $notify = VmPgsqlCore::getNotify($conn, $mode);
        if (false === $notify) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($notify);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_get_notify() is not implemented for JIT (#20681)');
    }
}

/**
 * pg_result_status (php-src ext/pgsql/pgsql.c; #20702).
 */
final class pg_result_status extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_result_status');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_result_status() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_result_status', 1);
        $mode = PgsqlConstants::PGSQL_STATUS_LONG;
        if (2 === $argc) {
            $modeVar = $frame->calledArgs[1]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $modeVar->type
                && \PHPCompiler\VM\Variable::TYPE_FLOAT !== $modeVar->type
                && \PHPCompiler\VM\Variable::TYPE_BOOLEAN !== $modeVar->type
                && \PHPCompiler\VM\Variable::TYPE_STRING !== $modeVar->type
            ) {
                throw new \TypeError(\sprintf(
                    'pg_result_status(): Argument #2 ($mode) must be of type int, %s given',
                    match ($modeVar->type) {
                        \PHPCompiler\VM\Variable::TYPE_NULL => 'null',
                        \PHPCompiler\VM\Variable::TYPE_ARRAY => 'array',
                        \PHPCompiler\VM\Variable::TYPE_OBJECT => 'object',
                        default => 'mixed',
                    }
                ));
            }
            $mode = (int) $modeVar->toInt();
        }
        $out = VmPgsqlCore::resultStatus($result, $mode);
        if (\is_int($out)) {
            $frame->returnVar->int($out);
        } else {
            $frame->returnVar->string($out);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_result_status() is not implemented for JIT (#20702)');
    }
}

/**
 * pg_get_pid (php-src ext/pgsql/pgsql.c; #20702).
 */
final class pg_get_pid extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_get_pid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_get_pid() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_get_pid', 1);
        $frame->returnVar->int(VmPgsqlCore::getPid($conn));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_get_pid() is not implemented for JIT (#20702)');
    }
}
