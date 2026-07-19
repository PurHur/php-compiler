<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * odbc_connect() — php-src ext/odbc/php_odbc.c (#6293).
 */
final class odbc_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_connect() expects between 1 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dsn = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'odbc_connect', 0, 'dsn');
        $user = null;
        $password = null;
        $cursor = OdbcConstants::SQL_CUR_DEFAULT;
        if ($argc >= 2) {
            $user = self::nullablePathArg($frame->calledArgs[1], 'odbc_connect', 1, 'user');
        }
        if ($argc >= 3) {
            $password = self::nullablePathArg($frame->calledArgs[2], 'odbc_connect', 2, 'password');
        }
        if (4 === $argc) {
            $cursor = self::cursorOpt($frame->calledArgs[3]);
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_connect() requires a VM context');
        }
        $result = VmOdbcCore::connect($dsn, $user, $password, $cursor, $ctx, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_connect() is not implemented for JIT in this compiler build (issue #6293)');
    }

    private static function nullablePathArg(Variable $var, string $fn, int $idx, string $name): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $fn, $idx, $name);
    }

    private static function cursorOpt(Variable $var): int
    {
        $var = $var->resolveIndirect();
        $opt = $var->toInt();
        if (OdbcConstants::SQL_CUR_DEFAULT !== $opt
            && OdbcConstants::SQL_CUR_USE_IF_NEEDED !== $opt
            && OdbcConstants::SQL_CUR_USE_ODBC !== $opt
            && OdbcConstants::SQL_CUR_USE_DRIVER !== $opt
        ) {
            throw new \ValueError(
                'odbc_connect(): Argument #4 ($cursor_option) must be one of SQL_CUR_USE_IF_NEEDED, SQL_CUR_USE_ODBC, or SQL_CUR_USE_DRIVER'
            );
        }

        return $opt;
    }
}
