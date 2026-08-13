<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for match expression subset (#2398, #2428). */
final class MatchVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (
            [
                'match_int.phpt',
                'match_identical.phpt',
                'match_literal.phpt',
                'match_default.phpt',
                'match_default_arm.phpt',
                'match_guard.phpt',
                'match_guard_falsy.phpt',
                'match_arm_assign.phpt',
                'match_unhandled.phpt',
                'match_unhandled_string_22820.phpt',
                'match_unhandled_message_23664.phpt',
                'match_unhandled_message_variable_24329.phpt',
                'match_enum_unhandled.phpt',
                'match_object_unhandled.phpt',
                'is_object_enum_case.phpt',
                '../stdlib/is_int_object_enum.phpt',
                'match_strict_arms_4371.phpt',
                'match_enum_case.phpt',
                'match_enum_case_scalar.phpt',
                'match_scalar_enum_arm.phpt',
                'match_enum_subject_scalar_arm.phpt',
                'match_enum_consecutive.phpt',
                'match_enum_default.phpt',
                'match_nested_call_arg.phpt',
                'match_default_only_call_arg.phpt',
                'match_switch_enum_strict.phpt',
                'match_switch_enum_unqualified.phpt',
                'match_enum_case_qualified.phpt',
                'switch_match_enum_typed_subject.phpt',
                'match_duplicate_default.phpt',
                'match_default_not_last.phpt',
                'match_typed_class_const_forward83.phpt',
                'match_class_const_compile_error.phpt',
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
