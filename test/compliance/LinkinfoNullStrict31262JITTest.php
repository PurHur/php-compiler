<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: linkinfo(null) TypeError under strict_types (#31262). */
final class LinkinfoNullStrict31262JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'linkinfo_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/linkinfo_null_strict_jit.phpt',
            'linkinfo_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
