<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: $obj?->missing ?? default silent like $obj->missing ?? (#30030).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class NullsafeCoalesceUndefPropSilent30030JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'nullsafe_coalesce_undef_prop_silent_30030.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/nullsafe_coalesce_undef_prop_silent_30030.phpt',
            'nullsafe_coalesce_undef_prop_silent_30030.phpt'
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
