<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: password_* null $options → TypeError (#31421).
 */
final class PasswordNullOptions31421JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'password_null_options_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/password_null_options_typeerror_jit.phpt',
            'password_null_options_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
