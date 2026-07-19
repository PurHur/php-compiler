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
 * odbc_pconnect() — php-src ext/odbc/php_odbc.c (#6293).
 *
 * Phase 1: same as odbc_connect (persistent pool not yet retained).
 */
final class odbc_pconnect extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_pconnect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_pconnect() expects between 1 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dsn = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'odbc_pconnect', 0, 'dsn');
        $user = null;
        $password = null;
        $cursor = OdbcConstants::SQL_CUR_DEFAULT;
        if ($argc >= 2) {
            $user = self::nullablePathArg($frame->calledArgs[1], 'odbc_pconnect', 1, 'user');
        }
        if ($argc >= 3) {
            $password = self::nullablePathArg($frame->calledArgs[2], 'odbc_pconnect', 2, 'password');
        }
        if (4 === $argc) {
            $cursor = $frame->calledArgs[3]->toInt();
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_pconnect() requires a VM context');
        }
        $result = VmOdbcCore::connect($dsn, $user, $password, $cursor, $ctx, $frame, 'odbc_pconnect');
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_pconnect() is not implemented for JIT in this compiler build (issue #6293)');
    }

    private static function nullablePathArg(Variable $var, string $fn, int $idx, string $name): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $fn, $idx, $name);
    }
}
