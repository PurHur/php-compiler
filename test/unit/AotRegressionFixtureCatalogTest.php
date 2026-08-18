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
        'dateperiod_foreach_format.phpt',
        'nullsafe_nested_coalesce_26818.phpt',
        'arrayobject_foreach.phpt',
        'arrayobject_foreach_encapsed.phpt',
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
        'simplexml/load_string_child_property_cast.phpt',
        'static_property_closure_write_read_31965.phpt',
        'pow_operator_int_31966.phpt',
        'base_convert_255_31966.phpt',
        'property_exists_static_31966.phpt',
        'function_static_string_write_31966.phpt',
        'function_static_array_inc_32305.phpt',
        'static_property_assign_ref_32036.phpt',
        'static_array_byvalue_copy_32307.phpt',
        'static_prop_method_inc_32313.phpt',
        'json_encode_nan.phpt',
        'unary_minus_neg_inf_32317.phpt',
        'str_shuffle_named_params.phpt',
        'metaphone_soundex_count_chars_named_23437.phpt',
        'debug_print_backtrace_void_28909.phpt',
        'array_callable_static_invoke.phpt',
        'dom_createdocumentfragment_savexml.phpt',
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
