<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: ini_parse_quantity Reflection + Zend named param (#23405).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class IniParseQuantityNamed23405VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ini_parse_quantity_named_23405.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/ini_parse_quantity_named_23405.phpt',
            'ini_parse_quantity_named_23405.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
