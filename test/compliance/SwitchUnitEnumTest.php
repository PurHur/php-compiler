<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance: unit enum switch identity match (#8806). */
final class SwitchUnitEnumTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'switch_unit_enum.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/switch_unit_enum.phpt',
            'switch_unit_enum.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
