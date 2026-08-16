<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: http_build_query(null $numeric_prefix) soft-null DEP (#29721). */
final class HttpBuildQueryNullPrefixSoftJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'http_build_query_null_prefix_soft_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/http_build_query_null_prefix_soft_jit.phpt',
            'http_build_query_null_prefix_soft_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
