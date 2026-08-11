<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: (array)false === [false] (#30097).
 */
require_once __DIR__.'/../BaseTest.php';

final class CastFalseToArray30097VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cast_false_to_array.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/cast_false_to_array.phpt',
            'cast_false_to_array.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
