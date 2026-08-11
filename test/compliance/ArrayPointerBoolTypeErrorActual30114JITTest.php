<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: reset/next/prev/current/key/end(false|true) TypeError says false|true (#30114).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class ArrayPointerBoolTypeErrorActual30114JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_pointer_bool_typeerror_actual.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pointer_bool_typeerror_actual.phpt',
            'array_pointer_bool_typeerror_actual.phpt'
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
