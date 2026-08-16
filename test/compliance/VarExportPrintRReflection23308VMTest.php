<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: var_export/print_r Reflection value + named value: (#23308).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class VarExportPrintRReflection23308VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'var_export_print_r_reflection_23308.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/var_export_print_r_reflection_23308.phpt',
            'var_export_print_r_reflection_23308.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
