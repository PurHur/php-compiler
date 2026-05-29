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
                'backed_enum_decl.phpt',
                'enum_case_name_value.phpt',
                'enum_cases.phpt',
                'enum_from.phpt',
                'enum_try_from.phpt',
                'enum_instanceof.phpt',
                'enum_implements_metadata.phpt',
                'enum_implements_interface.phpt',
                'enum_static_method.phpt',
                'unit_enum_basic.phpt',
                'unit_enum_case_name.phpt',
                'enum_user_method.phpt',
                'enum_backed_user_method.phpt',
                'enum_method.phpt',
                'abstract_enum.phpt',
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
