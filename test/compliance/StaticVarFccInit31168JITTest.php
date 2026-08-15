<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: function-static FCC init — fatal on ≤8.2, legal on 8.3+ (#31168).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class StaticVarFccInit31168JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'static_var_fcc_init_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_var_fcc_init_fatal.phpt',
            'static_var_fcc_init_fatal.phpt'
        );
        yield 'static_var_fcc_init_83.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_var_fcc_init_83.phpt',
            'static_var_fcc_init_83.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
