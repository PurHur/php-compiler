<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: pg_query / pg_fetch_assoc / pg_fetch_row / pg_close Reflection matches Zend stubs (#28782).
 */
final class PgsqlQueryFetchCloseReflection28782VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pgsql_query_fetch_close_reflection_28782.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/pgsql_query_fetch_close_reflection_28782.phpt',
            'pgsql_query_fetch_close_reflection_28782.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
