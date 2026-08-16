<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: pg_connect/pg_pconnect Reflection connection_string/flags → PgSql\Connection|false (#27811).
 */
final class PgsqlConnectReflection27811VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pgsql_connect_reflection_27811.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/pgsql_connect_reflection_27811.phpt',
            'pgsql_connect_reflection_27811.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
