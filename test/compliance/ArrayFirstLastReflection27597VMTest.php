<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_first/array_last Reflection types match Zend 8.5 stubs (#27597).
 *
 * Dedicated provider — PROFILE=8.5 required to register array_first / array_last.
 */
final class ArrayFirstLastReflection27597VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_first_last_reflection_27597.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_first_last_reflection_27597.phpt',
            'array_first_last_reflection_27597.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
