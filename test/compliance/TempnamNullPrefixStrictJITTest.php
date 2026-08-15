<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: tempnam(null $prefix) TypeError under strict_types (#31246). */
final class TempnamNullPrefixStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'tempnam_null_prefix_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/tempnam_null_prefix_strict_jit.phpt',
            'tempnam_null_prefix_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
