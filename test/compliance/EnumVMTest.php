<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for backed enums (#1356, #3083). */
final class EnumVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (
            [
                'enum_basic.phpt',
                'enum_case_name_value.phpt',
                'enum_implements_metadata.phpt',
                'enum_static_method.phpt',
            ] as $file
        ) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
