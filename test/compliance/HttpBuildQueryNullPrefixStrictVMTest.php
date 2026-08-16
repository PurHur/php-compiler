<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: http_build_query(null $numeric_prefix) TypeError under strict_types (#29721). */
final class HttpBuildQueryNullPrefixStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'http_build_query_null_prefix_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/http_build_query_null_prefix_strict.phpt',
            'http_build_query_null_prefix_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
