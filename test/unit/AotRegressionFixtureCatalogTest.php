<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Always-on gate for curated AOT PHPT fixtures (#3671+ regressions).
 *
 * Runs in the default unit testsuite (no LLVM link). Full native link coverage
 * remains in {@see \PHPCompiler\AotTest} (@group aot-link) via ci-local.sh.
 */
final class AotRegressionFixtureCatalogTest extends TestCase
{
    /**
     * Regression fixtures added for recent language/stdlib merges.
     * Each must exist under test/fixtures/aot/cases/ and compile in MODE_AOT.
     *
     * @var list<string>
     */
    private const REGRESSION_FIXTURES = [
        'echo_spaceship.phpt',
        'empty_array_loose_false.phpt',
        'object_identical.phpt',
        'basename_suffix.phpt',
        'stdlib_predefined_constants.phpt',
        'gettype_object_resource.phpt',
        'is_countable.phpt',
        'crc32c.phpt',
        'anonymous_class.phpt',
        'anonymous_class_ctor.phpt',
        'readonly_property_write.phpt',
        'readonly_property_unset.phpt',
        'readonly_property_inc.phpt',
        'readonly_property_dec.phpt',
        'readonly_property_compound_assign.phpt',
        'readonly_property_dot_assign.phpt',
        'readonly_property_promoted.phpt',
        'readonly_property_coalesce_assign.phpt',
        'late_static_binding.phpt',
        'late_static_method_dispatch.phpt',
        'new_static.phpt',
        'match_guard_falsy.phpt',
        'fcc_new_instance.phpt',
        'strlen_script_global.phpt',
        'array_keys_nested_producer.phpt',
        'array_keys_getarraycopy_nested.phpt',
        'named_args_skip_first_optional.phpt',
        'byref_param_int.phpt',
        'byref_untyped_param_assign_e06.phpt',
        'dateperiod_foreach_format.phpt',
        'nullsafe_nested_coalesce_26818.phpt',
        'nullsafe_prop_reassign_32749.phpt',
        'nullsafe_prop_dominates_32988.phpt',
        'arrayobject_foreach.phpt',
        'arrayobject_foreach_encapsed.phpt',
        'arrayobject_exchangearray_33083.phpt',
        'arrayobject_ternary_echo_concat_33094.phpt',
        'dom_attributes_foreach_33099.phpt',
        'dom_namednodemap_getnameditem_33107.phpt',
        'dom_namednodemap_getnameditemns_33116.phpt',
        'dom_attributes_map_length_33128.phpt',
        'spl_outer_iterators_ita.phpt',
        'weakreference_main_unset.phpt',
        'reflection_new_lazy_ghost_property.phpt',
        'reflection_constant_get_name_value.phpt',
        'phptoken_tokenize_gettokenname.phpt',
        'fdiv_inf_nan_is_nan.phpt',
        'sys_getloadavg_count.phpt',
        'count_array_literal.phpt',
        'mb_str_pad_pad_type_literal_forward_84.phpt',
        'strcoll_signs.phpt',
        'stream_get_contents_php_memory.phpt',
        'http_build_query_nested_27031.phpt',
        'cast_object_resource.phpt',
        'cast_array_bool.phpt',
        'cast_object_bool.phpt',
        'cast_object_native_array.phpt',
        'object_bool_not_32471.phpt',
        'array_if_not_empty_32475.phpt',
        'object_array_logical_xor_32492.phpt',
        'array_ordered_compare_32501.phpt',
        'hashtable_ordered_compare_32524.phpt',
        'object_string_null_unlike_compare.phpt',
        'array_null_bool_unlike_compare.phpt',
        'array_runtime_null_bool_unlike_compare.phpt',
        'object_string_identical.phpt',
        'simplexml/load_string_child_property_cast.phpt',
        'static_property_closure_write_read_31965.phpt',
        'pow_operator_int_31966.phpt',
        'base_convert_255_31966.phpt',
        'property_exists_static_31966.phpt',
        'array_callable_after_closure_33800.phpt',
        'switch_superglobal_var_case_33800.phpt',
        'ack_twice_echo.phpt',
        'property_exists_boxed_instance_32688.phpt',
        'method_exists_class_string_32701.phpt',
        'is_a_class_string_32706.phpt',
        'property_exists_stdclass_peer_class_prop.phpt',
        'function_static_string_write_31966.phpt',
        'function_static_array_inc_32305.phpt',
        'function_static_string_dim_assign_32800.phpt',
        'local_string_dim_assign_32806.phpt',
        'function_static_string_dim_assign_32814.phpt',
        'function_static_string_concat_32889.phpt',
        'static_prop_string_concat_32899.phpt',
        'dom_childnode_replacewith_live_held_32822.phpt',
        'dom_nodelist_item_loop_32831.phpt',
        'dom_getelements_item_after_childnodes_34646.phpt',
        'dom_getelements_item_after_remove_34646.phpt',
        'dom_parentnode_append_multi_live_held_32838.phpt',
        'dom_item_ternary_concat_32908.phpt',
        'dom_loadhtml_nested_getelementbyid_32996.phpt',
        'array_dim_assign_op_32789.phpt',
        'dim_concat_pow_assign_op_32798.phpt',
        'static_property_assign_ref_32036.phpt',
        'static_array_byvalue_copy_32307.phpt',
        'static_prop_method_inc_32313.phpt',
        'json_encode_nan.phpt',
        'unary_minus_neg_inf_32317.phpt',
        'str_shuffle_named_params.phpt',
        'metaphone_soundex_count_chars_named_23437.phpt',
        'strcoll_strnatcmp_named_23694.phpt',
        'linkinfo_readlink_named_23944.phpt',
        'named_args_23507_base_convert_addcslashes_hash_file.phpt',
        'debug_print_backtrace_void_28909.phpt',
        'array_callable_static_invoke.phpt',
        'call_user_func_user_fn_35100.phpt',
        'unserialize_user_object_props_35107.phpt',
        'aot_catch_if_htmlspecialchars_32636.phpt',
        'dom_createdocumentfragment_savexml.phpt',
        'dom_createentityreference_savexml.phpt',
        'dom_clonenode_savexml.phpt',
        'dom_substringdata.phpt',
        'dom_appenddata.phpt',
        'dom_insertdata.phpt',
        'dom_deletedata.phpt',
        'dom_replacedata.phpt',
        'dom_wholetext.phpt',
        'dom_iswhitespace.phpt',
        'dom_setattrns.phpt',
        'long_string_bitwise_32407.phpt',
        'value_string_bitwise_32417.phpt',
        'string_string_bitwise_32431.phpt',
        'dom_gebtns.phpt',
        'ctor_promo_untyped_string_32349.phpt',
        'ctor_untyped_prop_assign_32363.phpt',
        'method_prop_assign_32367.phpt',
        'coalesce_undef_echo_32445.phpt',
        'object_int_float_cast_32452.phpt',
        'hash_update_stream.phpt',
        'hash_update_stream_length.phpt',
        'dom_hasfeature.phpt',
        'dom_implementation_createdocument.phpt',
        'object_array_bitwise_typeerror_32486.phpt',
        'dom_getlineno.phpt',
        'dateinterval_format_execute.phpt',
        'instance_property_coalesce_assign_33748.phpt',
        'dom/dom_hasattr_bool_33762.phpt',
        'dom/dom_getattrnode_false_33773.phpt',
        'filter_var_sanitize_full_special_chars.phpt',
        'string_shift_typeerror_35308.phpt',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function regressionFixturesProvider(): array
    {
        $cases = [];
        foreach (self::REGRESSION_FIXTURES as $basename) {
            if (!CompilerVersion::supportsCrc32c() && 'crc32c.phpt' === $basename) {
                continue;
            }
            $cases[$basename] = [$basename];
        }

        return $cases;
    }

    /**
     * @dataProvider regressionFixturesProvider
     */
    public function testRegressionFixtureExistsWithPhptSections(string $basename): void
    {
        $path = $this->fixturePath($basename);
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('--FILE--', $contents, $basename);
        $this->assertTrue(
            str_contains($contents, '--EXPECT--')
                || str_contains($contents, '--EXPECTF--')
                || str_contains($contents, '--EXPECTREGEX--'),
            $basename.' missing EXPECT section'
        );
    }

    /**
     * @dataProvider regressionFixturesProvider
     */
    public function testRegressionFixtureParseAndCompileInAotMode(string $basename): void
    {
        $path = $this->fixturePath($basename);
        $code = $this->extractFileSection($path);
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile($code, $path);
        $this->assertNotNull($block, 'parseAndCompile returned null for '.$basename);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function compileOnlyFixturesProvider(): array
    {
        return [
            'enum_instanceof.php' => ['enum_instanceof.php'],
            'enum_string_interpolation.php' => ['enum_string_interpolation.php'],
            'loose_numeric_string_eq.php' => ['loose_numeric_string_eq.php'],
            'loose_scientific_string_eq.php' => ['loose_scientific_string_eq.php'],
            'bool_increment.php' => ['bool_increment.php'],
            'intdiv_division_by_zero.php' => ['intdiv_division_by_zero.php'],
            'is_finite_numeric_string.php' => ['is_finite_numeric_string.php'],
            'ord_empty_4324.php' => ['ord_empty_4324.php'],
            'ord_float_strict_typeerror.php' => ['ord_float_strict_typeerror.php'],
            'array_union_plus.php' => ['array_union_plus.php'],
            'match_guard.php' => ['match_guard.php'],
            'str_getcsv_enum_typeerror.php' => ['str_getcsv_enum_typeerror.php'],
            'str_getcsv_null_typeerror.php' => ['str_getcsv_null_typeerror.php'],
            'urldecode_enum_typeerror.php' => ['urldecode_enum_typeerror.php'],
            'urlencode_enum_typeerror.php' => ['urlencode_enum_typeerror.php'],
            'password_needs_rehash_enum_typeerror.php' => ['password_needs_rehash_enum_typeerror.php'],
            'password_hash_argon2id_options.php' => ['password_hash_argon2id_options.php'],
            'password_hash_enum_case_typeerror.php' => ['password_hash_enum_case_typeerror.php'],
            'password_hash_null_password_coerce.php' => ['password_hash_null_password_coerce.php'],
            'json_encode_stringable.php' => ['json_encode_stringable.php'],
            'stat_is_link_enum_typeerror.php' => ['stat_is_link_enum_typeerror.php'],
            'fs_path_enum_typeerror.php' => ['fs_path_enum_typeerror.php'],
            'array_pad_chunk_enum.php' => ['array_pad_chunk_enum.php'],
            'array_udiff_family.php' => ['array_udiff_family.php'],
            'array_slice_numeric_string.php' => ['array_slice_numeric_string.php'],
            'array_search_strict_mixed_haystack.php' => ['array_search_strict_mixed_haystack.php'],
            'base_convert_enum_typeerror.php' => ['base_convert_enum_typeerror.php'],
            'trig_math_enum_case_typeerror.php' => ['trig_math_enum_case_typeerror.php'],
            'chop_pos_aliases.php' => ['chop_pos_aliases.php'],
            'strcoll_strxfrm.php' => ['strcoll_strxfrm.php'],
            'stream_copy_to_stream.php' => ['stream_copy_to_stream.php'],
            'stream_meta_blocking.php' => ['stream_meta_blocking.php'],
            'sort_named_params_23225.php' => ['sort_named_params_23225.php'],
        ];
    }

    /**
     * @dataProvider compileOnlyFixturesProvider
     */
    public function testCompileOnlyFixtureParseAndCompileInAotMode(string $basename): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/fixtures/aot/compile-only/'.$basename;
        $this->assertFileExists($path);
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    private function fixturePath(string $basename): string
    {
        return dirname(__DIR__, 2).'/test/fixtures/aot/cases/'.$basename;
    }

    private function extractFileSection(string $path): string
    {
        $sections = [];
        $section = '';
        foreach (file($path) as $line) {
            if (preg_match('/^--([_A-Z]+)--/', $line, $m)) {
                $section = $m[1];
                $sections[$section] = '';
                continue;
            }
            if ('' !== $section) {
                $sections[$section] .= $line;
            }
        }
        $this->assertArrayHasKey('FILE', $sections, 'missing --FILE-- in '.$path);

        return rtrim($sections['FILE'], "\r\n");
    }
}
