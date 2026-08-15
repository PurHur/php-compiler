<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: is_a/is_subclass_of(null $allow_string) TypeError under strict_types (#31339). */
final class IsASubclassNullAllowStringStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'is_a_subclass_null_allow_string_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/is_a_subclass_null_allow_string_strict_jit.phpt',
            'is_a_subclass_null_allow_string_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
