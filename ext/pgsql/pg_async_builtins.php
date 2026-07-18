<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * pg_socket / pg_consume_input / pg_flush (php-src ext/pgsql; #20636).
 * Loaded via Module::getFunctions() + spine require.
 */

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
            $connObj = VmPgsqlConnection::resolveOptionalConnection(null);
            if (null === $connObj) {
                @\trigger_error('pg_put_line(): No PostgreSQL connection opened yet', \E_USER_WARNING);
                $frame->returnVar->bool(false);

                return;
            }
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
        $connObj = VmPgsqlConnection::resolveOptionalConnection($provided);
        if (null === $connObj) {
            @\trigger_error('pg_end_copy(): No PostgreSQL connection opened yet', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
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
