<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: var_export($arr['key']->prop, true) exports the property value (#31938).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class VarExportArrayElementProperty31938VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'var_export_array_element_property.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/var_export_array_element_property.phpt',
            'var_export_array_element_property.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
