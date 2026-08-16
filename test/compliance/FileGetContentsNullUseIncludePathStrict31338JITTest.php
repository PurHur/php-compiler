<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: file_get_contents(..., null) $use_include_path under strict_types → TypeError (#31338). */
final class FileGetContentsNullUseIncludePathStrict31338JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'file_get_contents_null_use_include_path_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/file_get_contents_null_use_include_path_strict_jit.phpt',
            'file_get_contents_null_use_include_path_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
