<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: $a[] after negative-only keys continues nNextFreeElement (#30052, zend_hash.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class ArrayAppendAfterNegativeKeys30052JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_append_after_negative_keys.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_append_after_negative_keys.phpt',
            'array_append_after_negative_keys.phpt'
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
