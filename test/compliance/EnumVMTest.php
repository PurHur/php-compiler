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
                'enum_case_object.phpt',
                'enum_case_name_value_properties.phpt',
                'enum_case_file_const.phpt',
                'enum_case_define_const.phpt',
                'get_debug_type_enum.phpt',
                'enum_case_list_8_4.phpt',
                'enum_cases.phpt',
                'enum_cases_static.phpt',
                'enum_cases_backed_spread.phpt',
                'enum_cases_call_unpack.phpt',
                'enum_from.phpt',
                'enum_from_spaceship.phpt',
                'enum_from_int.phpt',
                'enum_from_valid.phpt',
                'enum_from_invalid.phpt',
                'enum_try_from.phpt',
                'enum_instanceof.phpt',
                'instanceof_enum_case.phpt',
                'enum_case_class_const.phpt',
                'enum_class_constant.phpt',
                'enum_type_constant.phpt',
                'enum_bare_const.phpt',
                'enum_interface_const.phpt',
                'enum_forward_interface_const.phpt',
                'enum_typed_class_constant.phpt',
                'enum_typed_class_const.phpt',
                'enum_illegal_array_offset_write.phpt',
                'enum_illegal_array_offset.phpt',
                'enum_array_illegal_offset.phpt',
                'enum_array_literal_offset.phpt',
                '../runtime/array_enum_case_key_typeerror.phpt',
                'array_enum_case_key_spread_union.phpt',
                'enum_case_fetch_object_int.phpt',
                'enum_strval.phpt',
                'clone_enum_case.phpt',
                'clone_enum_case_error.phpt',
                'enum_bitwise_shift_unary_typeerror.phpt',
                'enum_unary_minus_typeerror.phpt',
                'enum_pow_mod_typeerror.phpt',
                'enum_case_incdec_type_error.phpt',
                'enum_compare_backing_scalar.phpt',
                'enum_const_fetch_object.phpt',
                'switch_enum_case_scalar.phpt',
                'switch_enum_scalar_no_match.phpt',
                'switch_enum_scalar_subject.phpt',
                'switch_unit_enum.phpt',
                'enum_case_attributes.phpt',
                'enum_in_operator.phpt',
                'foreach_enum_case_by_value.phpt',
                'foreach_enum_case_by_ref.phpt',
                'enum_destructure_static.phpt',
                'list_destructure_enum_case.phpt',
                'enum_implements_metadata.phpt',
                'enum_implements_interface.phpt',
                'enum_implements_interface_missing.phpt',
                'enum_implements_interface_unit.phpt',
                'enum_static_method.phpt',
                'unit_enum_basic.phpt',
                'unit_enum_case_name.phpt',
                'enum_case_paren_invoke.phpt',
                'enum_user_method.phpt',
                'enum_throw_this_from_method.phpt',
                'enum_backed_user_method.phpt',
                'enum_backed_int_value.phpt',
                'enum_method.phpt',
                'enum_default_parameter.phpt',
                'abstract_enum.phpt',
                'duplicate_enum_backing_value.phpt',
                'enum_duplicate_backing_value.phpt',
                'enum_typed_param_dnf.phpt',
                'enum_typed_param_reject_backing_scalar.phpt',
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
