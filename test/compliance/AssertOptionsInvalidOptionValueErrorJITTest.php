<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: assert_options() invalid $option → ValueError (#30524, php-src assert.c). */
final class AssertOptionsInvalidOptionValueErrorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'assert_options_invalid_option_valueerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/assert_options_invalid_option_valueerror.phpt',
            'assert_options_invalid_option_valueerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
