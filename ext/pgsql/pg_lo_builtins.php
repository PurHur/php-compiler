<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * pg_lo_* builtins (php-src ext/pgsql/pgsql.c; #20587).
 * Loaded via Module::getFunctions() + spine require.
 */

final class pg_lo_create extends Internal
{
    public function __construct(string $name = 'pg_lo_create')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf('pg_lo_create() expects at most 2 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connObj = self::resolveConn($frame, $argc, 0, 'pg_lo_create');
        $native = VmPgsqlConnection::native($connObj);
        $oid = VmPgsqlNative::INVALID_OID;
        if ($argc >= 2) {
            $oidArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $oidArg->type) {
                $oid = $oidArg->toInt();
            }
        }
        if (VmPgsqlNative::INVALID_OID !== $oid) {
            $created = VmPgsqlNative::loCreate($native, $oid);
        } else {
            $created = VmPgsqlNative::loCreat($native, VmPgsqlNative::INV_READ | VmPgsqlNative::INV_WRITE);
        }
        if (VmPgsqlNative::INVALID_OID === $created) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($native));
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($created);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_create() is not implemented for JIT (#20587)');
    }

    private static function resolveConn(Frame $frame, int $argc, int $idx, string $fn): ObjectEntry
    {
        if ($argc > $idx) {
            $provided = VmPgsqlArg::optionalConnection($frame->calledArgs[$idx], $fn, $idx + 1);

            return VmPgsqlConnection::connectionOrDefaultDeprecated($provided, $frame, $fn);
        }

        return VmPgsqlConnection::connectionOrDefaultDeprecated(null, $frame, $fn);
    }
}

final class pg_lo_unlink extends Internal
{
    public function __construct(string $name = 'pg_lo_unlink')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf('pg_lo_unlink() expects between 1 and 2 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Forms: unlink(oid) | unlink(connection, oid)
        if (1 === $argc) {
            // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31221).
            $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated(null, $frame, 'pg_lo_unlink');
            $oid = $frame->calledArgs[0]->resolveIndirect()->toInt();
        } else {
            $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_lo_unlink', 1);
            $oid = $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        $native = VmPgsqlConnection::native($connObj);
        $ok = -1 !== VmPgsqlNative::loUnlink($native, $oid);
        if (!$ok) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($native));
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_unlink() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_open extends Internal
{
    public function __construct(string $name = 'pg_lo_open')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf('pg_lo_open() expects between 2 and 3 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('pg_lo_open() requires VM context');
        $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_lo_open', 1);
        $oid = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $modeStr = 'r';
        if (3 === $argc) {
            $modeStr = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pg_lo_open', 2, 'mode');
        }
        $mode = self::parseMode($modeStr);
        $native = VmPgsqlConnection::native($connObj);
        $fd = VmPgsqlNative::loOpen($native, $oid, $mode);
        if (-1 === $fd) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($native));
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmPgsqlLob::wrap($connObj, $fd, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_open() is not implemented for JIT (#20587)');
    }

    private static function parseMode(string $mode): int
    {
        $mode = strtolower($mode);
        $flags = 0;
        if (str_contains($mode, 'r')) {
            $flags |= VmPgsqlNative::INV_READ;
        }
        if (str_contains($mode, 'w')) {
            $flags |= VmPgsqlNative::INV_WRITE;
        }
        if (0 === $flags) {
            $flags = VmPgsqlNative::INV_READ;
        }

        return $flags;
    }
}

final class pg_lo_close extends Internal
{
    public function __construct(string $name = 'pg_lo_close')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'pg_lo_close() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $lob = VmPgsqlArg::requireLob($frame->calledArgs[0], 'pg_lo_close', 1);
        $conn = VmPgsqlLob::connection($lob);
        $ok = 0 === VmPgsqlNative::loClose(VmPgsqlConnection::native($conn), VmPgsqlLob::fd($lob));
        VmPgsqlLob::markClosed($lob);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_close() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_read extends Internal
{
    public function __construct(string $name = 'pg_lo_read')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf('pg_lo_read() expects between 1 and 2 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $lob = VmPgsqlArg::requireLob($frame->calledArgs[0], 'pg_lo_read', 1);
        $len = 8192;
        if (2 === $argc) {
            $len = $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        if ($len < 0) {
            $len = 0;
        }
        $conn = VmPgsqlLob::connection($lob);
        $data = VmPgsqlNative::loRead(VmPgsqlConnection::native($conn), VmPgsqlLob::fd($lob), $len);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_read() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_write extends Internal
{
    public function __construct(string $name = 'pg_lo_write')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf('pg_lo_write() expects between 2 and 3 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $lob = VmPgsqlArg::requireLob($frame->calledArgs[0], 'pg_lo_write', 1);
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_lo_write', 1, 'data');
        $len = \strlen($data);
        if (3 === $argc) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $len = min($len, $arg->toInt());
            }
        }
        $conn = VmPgsqlLob::connection($lob);
        $n = VmPgsqlNative::loWrite(VmPgsqlConnection::native($conn), VmPgsqlLob::fd($lob), $data, $len);
        if (-1 === $n) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($n);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_write() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_read_all extends Internal
{
    public function __construct(string $name = 'pg_lo_read_all')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'pg_lo_read_all() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $lob = VmPgsqlArg::requireLob($frame->calledArgs[0], 'pg_lo_read_all', 1);
        $conn = VmPgsqlLob::connection($lob);
        $native = VmPgsqlConnection::native($conn);
        $fd = VmPgsqlLob::fd($lob);
        $total = 0;
        while (true) {
            $chunk = VmPgsqlNative::loRead($native, $fd, 8192);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            echo $chunk;
            $total += \strlen($chunk);
        }
        $frame->returnVar->int($total);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_read_all() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_seek extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_lo_seek');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf('pg_lo_seek() expects between 2 and 3 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $lob = VmPgsqlArg::requireLob($frame->calledArgs[0], 'pg_lo_seek', 1);
        $offset = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $whence = 1; // SEEK_CUR / PGSQL_SEEK_CUR
        if (3 === $argc) {
            $whence = $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        $conn = VmPgsqlLob::connection($lob);
        $ok = -1 !== VmPgsqlNative::loLseek(VmPgsqlConnection::native($conn), VmPgsqlLob::fd($lob), $offset, $whence);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_seek() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_tell extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_lo_tell');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'pg_lo_tell() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $lob = VmPgsqlArg::requireLob($frame->calledArgs[0], 'pg_lo_tell', 1);
        $conn = VmPgsqlLob::connection($lob);
        $frame->returnVar->int(VmPgsqlNative::loTell(VmPgsqlConnection::native($conn), VmPgsqlLob::fd($lob)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_tell() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_truncate extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_lo_truncate');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'pg_lo_truncate() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $lob = VmPgsqlArg::requireLob($frame->calledArgs[0], 'pg_lo_truncate', 1);
        $size = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $conn = VmPgsqlLob::connection($lob);
        $ok = 0 === VmPgsqlNative::loTruncate(VmPgsqlConnection::native($conn), VmPgsqlLob::fd($lob), $size);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_truncate() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_import extends Internal
{
    public function __construct(string $name = 'pg_lo_import')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf('pg_lo_import() expects between 1 and 2 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31221).
            $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated(null, $frame, 'pg_lo_import');
            $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_lo_import', 0, 'pathname');
        } else {
            $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_lo_import', 1);
            $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_lo_import', 1, 'pathname');
        }
        $native = VmPgsqlConnection::native($connObj);
        $oid = VmPgsqlNative::loImport($native, $path);
        if (VmPgsqlNative::INVALID_OID === $oid) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($native));
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($oid);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_import() is not implemented for JIT (#20587)');
    }
}

final class pg_lo_export extends Internal
{
    public function __construct(string $name = 'pg_lo_export')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf('pg_lo_export() expects between 2 and 3 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (2 === $argc) {
            // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31221).
            $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated(null, $frame, 'pg_lo_export');
            $oid = $frame->calledArgs[0]->resolveIndirect()->toInt();
            $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_lo_export', 1, 'pathname');
        } else {
            $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_lo_export', 1);
            $oid = $frame->calledArgs[1]->resolveIndirect()->toInt();
            $path = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pg_lo_export', 2, 'pathname');
        }
        $ok = -1 !== VmPgsqlNative::loExport(VmPgsqlConnection::native($connObj), $oid, $path);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_lo_export() is not implemented for JIT (#20587)');
    }
}
