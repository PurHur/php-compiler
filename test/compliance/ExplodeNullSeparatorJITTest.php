<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: explode(null) DEP+ValueError on php-src-strict (#25942, re-#24695). */
final class ExplodeNullSeparatorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'explode_null_separator_valueerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/explode_null_separator_valueerror_jit.phpt',
            'explode_null_separator_valueerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
