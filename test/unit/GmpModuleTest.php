<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for ext/gmp phase-1/2 builtins (#3341, #19527). */
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
            __DIR__.'/../compliance/cases/gmp/gmp_init_null.phpt',
            'gmp_init_null.phpt'
        );
        yield 'gmp_init_null_forward84.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/gmp_init_null_forward84.phpt',
            'gmp_init_null_forward84.phpt'
        );
        yield 'gmp_class_final_forward84.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/gmp_class_final_forward84.phpt',
            'gmp_class_final_forward84.phpt'
        );
        yield 'gmp_class_extend_final_forward84.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/gmp_class_extend_final_forward84.phpt',
            'gmp_class_extend_final_forward84.phpt'
        );
        yield 'gmp_phase2_arith.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/phase2_arith.phpt',
            'gmp_phase2_arith.phpt'
        );
        yield 'gmp_phase3_arith.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/phase3_arith.phpt',
            'gmp_phase3_arith.phpt'
        );
        yield 'gmp_phase4_random_import.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/gmp/phase4_random_import.phpt',
            'gmp_phase4_random_import.phpt'
        );
    }
}
