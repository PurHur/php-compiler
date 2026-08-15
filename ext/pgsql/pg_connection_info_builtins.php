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
 * Connection info / health APIs (php-src ext/pgsql/pgsql.c; #20680).
 * Loaded via Module::getFunctions() + spine require.
 */

final class pg_version extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_version');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'pg_version() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $provided = null;
        if (1 === $argc) {
            $provided = VmPgsqlArg::optionalConnection($frame->calledArgs[0], 'pg_version', 1);
        }
        // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31220).
        $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated($provided, $frame, 'pg_version');
        $frame->returnVar->array(VmPgsqlCore::version($connObj));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_version() is not implemented for JIT (#20680)');
    }
}

final class pg_parameter_status extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_parameter_status');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_parameter_status() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            $name = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_parameter_status', 1, 'name');
            // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31220).
            $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated(null, $frame, 'pg_parameter_status');
        } else {
            $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_parameter_status', 1);
            $name = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_parameter_status', 2, 'name');
        }
        $status = VmPgsqlNative::parameterStatus(VmPgsqlConnection::native($connObj), $name);
        if (null === $status) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($status);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_parameter_status() is not implemented for JIT (#20680)');
    }
}

/** Shared 0–1 optional connection → string link-info builtin. */
abstract class pg_link_info_string extends Internal
{
    abstract protected function fetch(\FFI\CData $conn): string;

    public function execute(Frame $frame): void
    {
        $name = $this->name;
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 1 argument, %d given',
                $name,
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $provided = null;
        if (1 === $argc) {
            $provided = VmPgsqlArg::optionalConnection($frame->calledArgs[0], $name, 1);
        }
        // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31220).
        $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated($provided, $frame, $name);
        $frame->returnVar->string($this->fetch(VmPgsqlConnection::native($connObj)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->name.'() is not implemented for JIT (#20680)');
    }
}

final class pg_host extends pg_link_info_string
{
    public function __construct()
    {
        parent::__construct('pg_host');
    }

    protected function fetch(\FFI\CData $conn): string
    {
        return VmPgsqlNative::host($conn);
    }
}

final class pg_port extends pg_link_info_string
{
    public function __construct()
    {
        parent::__construct('pg_port');
    }

    protected function fetch(\FFI\CData $conn): string
    {
        return VmPgsqlNative::port($conn);
    }
}

final class pg_dbname extends pg_link_info_string
{
    public function __construct()
    {
        parent::__construct('pg_dbname');
    }

    protected function fetch(\FFI\CData $conn): string
    {
        return VmPgsqlNative::db($conn);
    }
}

final class pg_options extends pg_link_info_string
{
    public function __construct()
    {
        parent::__construct('pg_options');
    }

    protected function fetch(\FFI\CData $conn): string
    {
        return VmPgsqlNative::options($conn);
    }
}

final class pg_tty extends pg_link_info_string
{
    public function __construct()
    {
        parent::__construct('pg_tty');
    }

    protected function fetch(\FFI\CData $conn): string
    {
        return VmPgsqlNative::tty($conn);
    }
}

final class pg_client_encoding extends pg_link_info_string
{
    public function __construct(string $name = 'pg_client_encoding')
    {
        parent::__construct($name);
    }

    protected function fetch(\FFI\CData $conn): string
    {
        // Match pg_encoding_to_char(PQclientEncoding) via GUC when available.
        return VmPgsqlNative::parameterStatus($conn, 'client_encoding') ?? '';
    }
}

final class pg_set_client_encoding extends Internal
{
    public function __construct(string $name = 'pg_set_client_encoding')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_set_client_encoding() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            $encoding = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_set_client_encoding', 1, 'encoding');
            // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31220).
            $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated(null, $frame, 'pg_set_client_encoding');
        } else {
            $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_set_client_encoding', 1);
            $encoding = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_set_client_encoding', 2, 'encoding');
        }
        $frame->returnVar->int(VmPgsqlNative::setClientEncoding(VmPgsqlConnection::native($connObj), $encoding));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_set_client_encoding() is not implemented for JIT (#20680)');
    }
}

final class pg_ping extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_ping');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'pg_ping() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $provided = null;
        if (1 === $argc) {
            $provided = VmPgsqlArg::optionalConnection($frame->calledArgs[0], 'pg_ping', 1);
        }
        // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31220).
        $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated($provided, $frame, 'pg_ping');
        $frame->returnVar->bool(VmPgsqlCore::ping($connObj));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_ping() is not implemented for JIT (#20680)');
    }
}

final class pg_connection_reset extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_connection_reset');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_connection_reset() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_connection_reset', 1);
        $frame->returnVar->bool(VmPgsqlCore::connectionReset($conn));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_connection_reset() is not implemented for JIT (#20680)');
    }
}

final class pg_connection_busy extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_connection_busy');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_connection_busy() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_connection_busy', 1);
        $frame->returnVar->bool(VmPgsqlCore::connectionBusy($conn));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_connection_busy() is not implemented for JIT (#20680)');
    }
}

final class pg_connection_status extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_connection_status');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_connection_status() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_connection_status', 1);
        $frame->returnVar->int(VmPgsqlNative::status(VmPgsqlConnection::native($conn)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_connection_status() is not implemented for JIT (#20680)');
    }
}

final class pg_transaction_status extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_transaction_status');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_transaction_status() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_transaction_status', 1);
        $frame->returnVar->int(VmPgsqlNative::transactionStatus(VmPgsqlConnection::native($conn)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_transaction_status() is not implemented for JIT (#20680)');
    }
}
