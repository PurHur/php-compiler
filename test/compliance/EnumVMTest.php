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
                'enum_case_file_const.phpt',
                'enum_case_define_const.phpt',
                'get_debug_type_enum.phpt',
                'enum_cases.phpt',
                'enum_cases_static.phpt',
                'enum_cases_backed_spread.phpt',
                'enum_from.phpt',
                'enum_from_int.phpt',
                'enum_from_invalid.phpt',
                'enum_try_from.phpt',
                'enum_instanceof.phpt',
                'enum_case_fetch_object_int.phpt',
                'enum_case_incdec_type_error.phpt',
                'enum_in_operator.phpt',
                'enum_implements_metadata.phpt',
                'enum_implements_interface.phpt',
                'enum_static_method.phpt',
                'unit_enum_basic.phpt',
                'unit_enum_case_name.phpt',
                'enum_user_method.phpt',
                'enum_backed_user_method.phpt',
                'enum_backed_int_value.phpt',
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
