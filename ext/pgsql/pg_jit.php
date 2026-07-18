<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * pg_jit() — SHOW jit / jit_provider (php-src ext/pgsql; #7083).
 */
final class pg_jit extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_jit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'pg_jit() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            throw new \Error('pg_jit(): no PostgreSQL connection opened yet');
        }
        $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_jit', 1);
        $conn = VmPgsqlConnection::native($connObj);
        $ht = new HashTable();
        foreach (['jit_provider', 'jit'] as $param) {
            $res = VmPgsqlNative::exec($conn, 'SHOW '.$param);
            $slot = new Variable();
            if (null === $res || VmPgsqlNative::PGRES_TUPLES_OK !== VmPgsqlNative::resultStatus($res)) {
                $slot->null();
            } else {
                $slot->string(VmPgsqlNative::getvalue($res, 0, 0));
            }
            if (null !== $res) {
                VmPgsqlNative::clear($res);
            }
            $ht->add($param, $slot);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_jit() is not implemented for JIT (#7083)');
    }
}
