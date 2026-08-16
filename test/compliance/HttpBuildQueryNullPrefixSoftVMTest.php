<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: http_build_query(null $numeric_prefix) soft-null DEP (#29721). */
final class HttpBuildQueryNullPrefixSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'http_build_query_null_prefix_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/http_build_query_null_prefix_soft.phpt',
            'http_build_query_null_prefix_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
