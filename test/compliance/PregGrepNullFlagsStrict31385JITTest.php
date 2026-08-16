<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: preg_grep(..., null) $flags under strict_types → TypeError (#31385).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class PregGrepNullFlagsStrict31385JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'preg_grep_null_flags_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/preg_grep_null_flags_strict_jit.phpt',
            'preg_grep_null_flags_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
