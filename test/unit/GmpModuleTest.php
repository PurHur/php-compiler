<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for ext/gmp phase-1 builtins (#3341). */
final class GmpModuleTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'gmp_add_cmp.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/add_cmp.phpt',
            'gmp_add_cmp.phpt'
        );
        yield 'gmp_init_null.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/init_null.phpt',
            'gmp_init_null.phpt'
        );
        yield 'gmp_init_invalid_type.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/init_invalid_type.phpt',
            'gmp_init_invalid_type.phpt'
        );
    }
}
