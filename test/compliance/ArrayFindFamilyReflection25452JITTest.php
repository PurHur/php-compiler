<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: array_find family Reflection types match Zend 8.4 stubs (#25452).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest;
 * PROFILE=8.4 required to register array_find / array_find_key / array_any / array_all.
 */
final class ArrayFindFamilyReflection25452JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_find_family_reflection_25452.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_find_family_reflection_25452.phpt',
            'array_find_family_reflection_25452.phpt'
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
