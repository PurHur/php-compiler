<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: iterator_* (false|true) TypeError says false|true (#30117).
 */
require_once __DIR__.'/../BaseTest.php';

final class IteratorBoolTypeErrorActual30117JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iterator_bool_typeerror_actual.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/iterator_bool_typeerror_actual.phpt',
            'iterator_bool_typeerror_actual.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
