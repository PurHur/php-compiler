<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for typed property ++/-- expression values via var_export (#26491 / re-#10123).
 */
final class PropertyPostincReturnTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'property_postinc_return.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/property_postinc_return.phpt',
            'property_postinc_return.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
