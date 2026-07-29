<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\BuiltinParamNames;
use PHPUnit\Framework\TestCase;

final class BuiltinParamNamesAliasTest extends TestCase
{
    public function testNumberFormatCanonicalNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('number_format');
        self::assertNotNull($names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'decimal_separator', 'number_format'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'thousands_separator', 'number_format'));
    }

    public function testNumberFormatLegacyAliasNamesAreRejected(): void
    {
        $names = BuiltinParamNames::forFunction('number_format');
        self::assertNotNull($names);
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'dec_point', 'number_format'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'thousands_sep', 'number_format'));
    }

    public function testImplodeNamedSeparatorAndArrayResolve(): void
    {
        $names = BuiltinParamNames::forFunction('implode');
        self::assertSame(['separator', 'array'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'separator', 'implode'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'array', 'implode'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'glue', 'implode'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'pieces', 'implode'));
    }

    public function testHtmlspecialcharsDoubleEncodeNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('htmlspecialchars');
        self::assertSame(['string', 'flags', 'encoding', 'double_encode'], $names);
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'double_encode', 'htmlspecialchars'));
        $entities = BuiltinParamNames::forFunction('htmlentities');
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($entities, 'double_encode', 'htmlentities'));
    }

    public function testLevenshteinNamedCostParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('levenshtein');
        self::assertSame(
            ['string1', 'string2', 'insertion_cost', 'replacement_cost', 'deletion_cost'],
            $names
        );
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'insertion_cost', 'levenshtein'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'replacement_cost', 'levenshtein'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'deletion_cost', 'levenshtein'));
    }

    /** @covers issue #10319 */
    public function testVersionCompareOperatorNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('version_compare');
        self::assertSame(['version1', 'version2', 'operator'], $names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'operator', 'version_compare'));
    }

    /** @covers issue #10321 */
    public function testInArrayStrictNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('in_array');
        self::assertSame(['needle', 'haystack', 'strict'], $names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'strict', 'in_array'));
    }

    /** @covers issue #10321 */
    public function testArraySearchStrictNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('array_search');
        self::assertSame(['needle', 'haystack', 'strict'], $names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'strict', 'array_search'));
    }

    /** @covers issue #10469 */
    public function testArrayRandNumNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('array_rand');
        self::assertSame(['array', 'num'], $names);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'num', 'array_rand'));
    }

    /** @covers issue #10485 */
    public function testDebugBacktraceNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('debug_backtrace');
        self::assertSame(['options', 'limit'], $names);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'limit', 'debug_backtrace'));
    }

    /** @covers issue #10320 */
    public function testSubstrCompareNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('substr_compare');
        self::assertSame(['haystack', 'needle', 'offset', 'length', 'case_insensitive'], $names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'offset', 'substr_compare'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'length', 'substr_compare'));
    }

    /** @covers issue #10474 / #24454 */
    public function testFileFlagsNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('file');
        self::assertSame(['filename', 'flags', 'context'], $names);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'file'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'file'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('file'));
    }

    /** @covers issue #9565 */
    public function testPathinfoFlagsNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('pathinfo');
        self::assertSame(['path', 'flags'], $names);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'pathinfo'));
    }

    /** @covers issue #9620 */
    public function testExtractFlagsNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('extract');
        self::assertSame(['array', 'flags', 'prefix'], $names);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'extract'));
    }

    /** @covers issue #10644 */
    public function testMicrotimeAsFloatNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('microtime');
        self::assertSame(['as_float'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'as_float', 'microtime'));
    }

    /** @covers issue #11578 */
    public function testMemoryGetUsageNamedRealUsageParamResolves(): void
    {
        foreach (['memory_get_usage', 'memory_get_peak_usage'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['real_usage'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'real_usage', $fn));
        }
    }

    /** @covers issue #11577 */
    public function testUnpackNamedFormatStringParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('unpack');
        self::assertSame(['format', 'string', 'offset'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'format', 'unpack'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'unpack'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'offset', 'unpack'));
    }

    /** @covers issue #16887 */
    public function testOpensslCipherLengthCipherAlgoNamedParamResolves(): void
    {
        foreach (['openssl_cipher_iv_length', 'openssl_cipher_key_length'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['cipher_algo'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'cipher_algo', $fn));
        }
    }

    /** @covers issue #23626 */
    public function testOpensslRandomPseudoBytesZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('openssl_random_pseudo_bytes');
        self::assertSame(['length', 'strong_result'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'length', 'openssl_random_pseudo_bytes'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'strong_result', 'openssl_random_pseudo_bytes'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $returned_strong_result)
        self::assertFalse(
            BuiltinParamNames::lookupNamedParamIndex($names, 'returned_strong_result', 'openssl_random_pseudo_bytes')
        );
    }

    /** @covers issue #16886 */
    public function testMbConvertEncodingNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('mb_convert_encoding');
        self::assertSame(['string', 'to_encoding', 'from_encoding'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'mb_convert_encoding'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'to_encoding', 'mb_convert_encoding'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'from_encoding', 'mb_convert_encoding'));
    }

    /** @covers issue #16885 */
    public function testMbSearchEncodingNamedParamsResolve(): void
    {
        foreach (['mb_stripos', 'mb_strpos', 'mb_strripos', 'mb_strrpos'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['haystack', 'needle', 'offset', 'encoding'], $names, $fn);
            self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'encoding', $fn), $fn);
        }
    }

    /** @covers issue #23350 */
    public function testMbStrstrFamilyBeforeNeedleNamedParamsResolve(): void
    {
        foreach (['mb_strstr', 'mb_stristr', 'mb_strrchr', 'mb_strrichr'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['haystack', 'needle', 'before_needle', 'encoding'], $names, $fn);
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'before_needle', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'part', $fn), $fn);
        }
    }

    /** @covers issue #10027 #23224 #24039 */
    public function testTrimCharactersNamedParamResolves(): void
    {
        foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string', 'characters'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'characters', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'mode', $fn));
        }
    }

    /** @covers issue #10637 / #24449 — stub shape callback + ...args (basic_functions.stub.php) */
    public function testCallUserFuncVariadicNamedParamMetadata(): void
    {
        $names = BuiltinParamNames::forFunction('call_user_func');
        self::assertSame(['callback', 'args'], $names);
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('call_user_func'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('call_user_func'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('call_user_func'));
        self::assertSame(['callback', 'args'], BuiltinParamNames::forFunction('call_user_func_array'));
        self::assertTrue(BuiltinParamNames::forwardsNamedArgsIntoVariadic('call_user_func'));
        self::assertFalse(BuiltinParamNames::forwardsNamedArgsIntoVariadic('forward_static_call'));
        self::assertFalse(BuiltinParamNames::forwardsNamedArgsIntoVariadic('max'));
    }

    /** @covers issue #23803 */
    public function testCompactVariadicNamedParamMetadata(): void
    {
        $names = BuiltinParamNames::forFunction('compact');
        self::assertSame(['var_name', 'var_names'], $names);
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('compact'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('compact'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('compact'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'var_name', 'compact'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'var_names', 'compact'));
    }

    /** @covers issue #22825 */
    public function testInternalPrintfFamilyAndArrayVariadicReflectionArity(): void
    {
        $cases = [
            'sprintf' => [1, 1, 2],
            'printf' => [1, 1, 2],
            'array_merge' => [0, 0, 1],
            'array_push' => [1, 1, 2],
            'max' => [1, 1, 2],
            'min' => [1, 1, 2],
            'array_map' => [2, 2, 3],
        ];
        foreach ($cases as $fn => [$index, $required, $total]) {
            self::assertSame($index, BuiltinParamNames::variadicParamIndexForFunction($fn), $fn.' variadic index');
            self::assertSame($required, BuiltinParamNames::requiredParamCountForInternalFunction($fn), $fn.' required');
            self::assertSame($total, BuiltinParamNames::paramCountForInternalFunction($fn), $fn.' total');
        }
        self::assertSame(['format', 'values'], BuiltinParamNames::forFunction('sprintf'));
        self::assertSame(['arrays'], BuiltinParamNames::forFunction('array_merge'));
        self::assertNull(BuiltinParamNames::variadicParamIndexForFunction('strlen'));
    }

    /** @covers issue #23181 */
    public function testInternalRequiredCountsPreferArgInfoOverBareNameTables(): void
    {
        $cases = [
            'substr' => 2,
            'json_encode' => 1,
            'json_decode' => 1,
            'explode' => 2,
            'preg_match' => 2,
            'hash' => 2,
            'openssl_encrypt' => 3,
            'array_slice' => 2,
        ];
        foreach ($cases as $fn => $required) {
            self::assertSame(
                $required,
                BuiltinParamNames::requiredParamCountForInternalFunction($fn),
                $fn.' required'
            );
        }
    }

    /** @covers issue #10042 */
    public function testArrayColumnNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('array_column');
        self::assertSame(['array', 'column_key', 'index_key'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', 'array_column'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'input', 'array_column'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'column_key', 'array_column'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'index_key', 'array_column'));
    }

    /** @covers issue #10045 */
    public function testFileGetContentsFilenameNamedParameter(): void
    {
        $names = BuiltinParamNames::forFunction('file_get_contents');
        self::assertSame(['filename', 'use_include_path', 'context', 'offset', 'length'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'filename', 'file_get_contents'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'offset', 'file_get_contents'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'length', 'file_get_contents'));
    }

    /** @covers issue #10060 */
    public function testPregSplitNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('preg_split');
        self::assertSame(['pattern', 'subject', 'limit', 'flags'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'pattern', 'preg_split'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'subject', 'preg_split'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'limit', 'preg_split'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'preg_split'));
    }

    /** @covers issue #10028 */
    public function testIniGetSetNamedParameters(): void
    {
        $get = BuiltinParamNames::forFunction('ini_get');
        self::assertSame(['option'], $get);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($get, 'option', 'ini_get'));

        $set = BuiltinParamNames::forFunction('ini_set');
        self::assertSame(['option', 'value'], $set);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($set, 'option', 'ini_set'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($set, 'value', 'ini_set'));
    }

    /** @covers issue #10126 / #23404 — Zend stub uses env_vars not env */
    public function testProcOpenNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('proc_open');
        self::assertSame(['command', 'descriptor_spec', 'pipes', 'cwd', 'env_vars', 'options'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'command', 'proc_open'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'descriptor_spec', 'proc_open'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'pipes', 'proc_open'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'cwd', 'proc_open'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'env_vars', 'proc_open'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'env', 'proc_open'));
    }

    /** @covers issue #16625 */
    public function testProcGetStatusNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('proc_get_status');
        self::assertSame(['process'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'process', 'proc_get_status'));

        $close = BuiltinParamNames::forFunction('proc_close');
        self::assertSame(['process'], $close);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($close, 'process', 'proc_close'));

        $terminate = BuiltinParamNames::forFunction('proc_terminate');
        self::assertSame(['process', 'signal'], $terminate);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($terminate, 'process', 'proc_terminate'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($terminate, 'signal', 'proc_terminate'));
    }

    /** @covers issue #10043 */
    public function testRandomIntNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('random_int');
        self::assertSame(['min', 'max'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'min', 'random_int'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'max', 'random_int'));
    }

    /** @covers issue #10033 */
    public function testPregReplaceNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('preg_replace');
        self::assertSame(['pattern', 'replacement', 'subject', 'limit', 'count'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'pattern', 'preg_replace'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'replacement', 'preg_replace'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'subject', 'preg_replace'));
    }

    /** @covers issue #19697 */
    public function testPregReplaceCallbackArrayNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('preg_replace_callback_array');
        self::assertSame(['pattern', 'subject', 'limit', 'count', 'flags'], $names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'limit', 'preg_replace_callback_array'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'count', 'preg_replace_callback_array'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'preg_replace_callback_array'));

        $cb = BuiltinParamNames::forFunction('preg_replace_callback');
        self::assertSame(['pattern', 'callback', 'subject', 'limit', 'count', 'flags'], $cb);
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($cb, 'count', 'preg_replace_callback'));
    }

    /** @covers issue #19697 — InternalArgInfo '&count' must resolve bare named count: */
    public function testLookupNamedParamIndexStripsByRefAmpersand(): void
    {
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex(['pattern', 'subject', 'limit', '&count'], 'count'));
    }

    /** @covers issue #10076 */
    public function testArrayAllAnyNamedParameters(): void
    {
        foreach (['array_all', 'array_any', 'array_find', 'array_find_key'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array', 'callback'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', $fn));
        }
    }

    /** @covers issue #9647 */
    public function testDateNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('date');
        self::assertSame(['format', 'timestamp'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'format', 'date'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'timestamp', 'date'));
    }

    /** @covers issue #9524 / #23191 */
    public function testWordwrapNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('wordwrap');
        self::assertSame(['string', 'width', 'break', 'cut_long_words'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'wordwrap'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'width', 'wordwrap'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'break', 'wordwrap'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'cut_long_words', 'wordwrap'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $cut)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'cut', 'wordwrap'));
    }

    /** @covers issue #9646 */
    public function testJsonEncodeNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('json_encode');
        self::assertSame(['value', 'flags', 'depth'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'value', 'json_encode'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'json_encode'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'depth', 'json_encode'));
    }

    /** @covers issue #10048 #23225 #23385 #23243 */
    public function testUsortNamedCallbackParameters(): void
    {
        foreach (['usort', 'uasort', 'uksort'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array', 'callback'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', $fn));
            // #23385 — withhold phantom direction unless Sorting/SortDirection profile gate is on.
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'direction', $fn), $fn);
        }
        // #23225 — sort/rsort are Zend array/flags only (no phantom direction).
        foreach (['sort', 'rsort'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array', 'flags'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'direction', $fn));
        }
        // #23243 — natsort/natcasesort are Zend array only (no phantom flags).
        foreach (['natsort', 'natcasesort'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'flags', $fn));
        }
    }

    /** @covers issue #16463 */
    public function testArraySumProductNamedParameters(): void
    {
        foreach (['array_sum', 'array_product'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
        }
    }

    /** @covers issue #11147 */
    public function testArrayPadNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('array_pad');
        self::assertSame(['array', 'length', 'value'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', 'array_pad'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'length', 'array_pad'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'value', 'array_pad'));
    }

    /** @covers issue #11145 */
    public function testArraySliceNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('array_slice');
        self::assertSame(['array', 'offset', 'length', 'preserve_keys'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', 'array_slice'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'offset', 'array_slice'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'length', 'array_slice'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'preserve_keys', 'array_slice'));
    }

    /** @covers issue #11346 */
    public function testArrayCombineNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('array_combine');
        self::assertSame(['keys', 'values'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'keys', 'array_combine'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'values', 'array_combine'));
    }

    /** @covers issue #11348 */
    public function testClearstatcacheNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('clearstatcache');
        self::assertSame(['clear_realpath_cache', 'filename'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'clear_realpath_cache', 'clearstatcache'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'filename', 'clearstatcache'));
    }

    /** @covers issue #11349, #23804 */
    public function testVariadicArrayBuiltinsRejectNamedParameters(): void
    {
        self::assertTrue(BuiltinParamNames::rejectsNamedParameters('pack'));
        foreach (['array_replace', 'array_merge', 'array_replace_recursive', 'array_merge_recursive'] as $fn) {
            self::assertFalse(BuiltinParamNames::rejectsNamedParameters($fn), $fn);
        }
        self::assertFalse(BuiltinParamNames::rejectsNamedParameters('array_combine'));
    }

    /** @covers issues #11111, #11112, #11113, #11114 */
    public function testStreamFamilyNamedParameters(): void
    {
        $fread = BuiltinParamNames::forFunction('fread');
        self::assertSame(['stream', 'length'], $fread);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fread, 'length', 'fread'));

        foreach (['fwrite', 'fputs'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['stream', 'data', 'length'], $names, $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'data', $fn));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'length', $fn));
        }

        $fputcsv = BuiltinParamNames::forFunction('fputcsv');
        self::assertSame(['stream', 'fields', 'separator', 'enclosure', 'escape', 'eol'], $fputcsv);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fputcsv, 'fields', 'fputcsv'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($fputcsv, 'separator', 'fputcsv'));
        self::assertSame(5, BuiltinParamNames::lookupNamedParamIndex($fputcsv, 'eol', 'fputcsv'));

        $splFputcsv = BuiltinParamNames::paramNamesForInternalFunction('SplFileObject::fputcsv');
        self::assertSame(['fields', 'separator', 'enclosure', 'escape', 'eol'], $splFputcsv);
        self::assertSame(
            3,
            BuiltinParamNames::lookupNamedParamIndex($splFputcsv, 'escape', 'SplFileObject::fputcsv')
        );
        self::assertSame(
            1,
            BuiltinParamNames::lookupNamedParamIndex($splFputcsv, 'delimiter', 'SplFileObject::fputcsv')
        );

        $ctx = BuiltinParamNames::forFunction('stream_context_create');
        self::assertSame(['options', 'params'], $ctx);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ctx, 'options', 'stream_context_create'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($ctx, 'params', 'stream_context_create'));

        $copy = BuiltinParamNames::forFunction('stream_copy_to_stream');
        self::assertSame(['from', 'to', 'length', 'offset'], $copy);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($copy, 'from', 'stream_copy_to_stream'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($copy, 'to', 'stream_copy_to_stream'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($copy, 'length', 'stream_copy_to_stream'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($copy, 'offset', 'stream_copy_to_stream'));

        $ssc = BuiltinParamNames::forFunction('stream_socket_client');
        self::assertSame(
            ['address', 'error_code', 'error_message', 'timeout', 'flags', 'context'],
            $ssc
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ssc, 'address', 'stream_socket_client'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($ssc, 'timeout', 'stream_socket_client'));
    }

    /** @covers issue #11576 */
    public function testStreamSocketClientNamedTimeoutParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('stream_socket_client');
        self::assertNotNull($names);
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'timeout', 'stream_socket_client'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'error_code', 'stream_socket_client'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'error_message', 'stream_socket_client'));
    }

    /** @covers issue #12101 */
    public function testAtan2HypotNamedParameters(): void
    {
        $atan2 = BuiltinParamNames::forFunction('atan2');
        self::assertSame(['y', 'x'], $atan2);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($atan2, 'y', 'atan2'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($atan2, 'x', 'atan2'));

        $hypot = BuiltinParamNames::forFunction('hypot');
        self::assertSame(['x', 'y'], $hypot);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($hypot, 'x', 'hypot'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($hypot, 'y', 'hypot'));
    }

    /** @covers issue #12102 */
    public function testFilestatFilenameNamedParameter(): void
    {
        foreach (['file_exists', 'is_readable', 'filesize', 'is_file', 'is_dir'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['filename'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'filename', $fn), $fn);
        }
    }

    /** @covers issue #12103 */
    public function testRoundNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('round');
        self::assertSame(['num', 'precision', 'mode'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'num', 'round'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'precision', 'round'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'mode', 'round'));
    }

    /** @covers issue #11785 */
    public function testDateTimeClassMethodNamedParameters(): void
    {
        $names = BuiltinParamNames::forClassMethod('DateTime::createFromFormat');
        self::assertSame(['format', 'datetime', 'timezone'], $names);
        self::assertSame(
            1,
            BuiltinParamNames::lookupNamedParamIndex($names, 'datetime', 'DateTime::createFromFormat')
        );

        $ctor = BuiltinParamNames::forClassMethod('DateTimeImmutable::__construct');
        self::assertSame(['datetime', 'timezone'], $ctor);
        self::assertSame(
            1,
            BuiltinParamNames::lookupNamedParamIndex($ctor, 'timezone', 'DateTimeImmutable::__construct')
        );
    }

    /** @covers issue #23707 */
    public function testDateIntervalConstructStubNamedParamsResolve(): void
    {
        $ctor = BuiltinParamNames::forClassMethod('DateInterval::__construct');
        self::assertSame(['duration'], $ctor);
        self::assertSame(
            ['duration'],
            BuiltinParamNames::paramNamesForInternalFunction('DateInterval::__construct')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ctor, 'duration', 'DateInterval::__construct'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($ctor, 'spec', 'DateInterval::__construct'));
    }

    /** @covers issue #24589 */
    public function testDateIntervalCreateFromDateStringStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forClassMethod('DateInterval::createFromDateString');
        self::assertSame(['datetime'], $names);
        self::assertSame(
            ['datetime'],
            BuiltinParamNames::paramNamesForInternalFunction('DateInterval::createFromDateString')
        );
        self::assertSame(
            0,
            BuiltinParamNames::lookupNamedParamIndex($names, 'datetime', 'DateInterval::createFromDateString')
        );
        self::assertFalse(
            BuiltinParamNames::lookupNamedParamIndex($names, 'time', 'DateInterval::createFromDateString')
        );
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('DateInterval', 'createFromDateString'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('DateInterval', 'createFromDateString'));
    }

    /** @covers issue #24592 */
    public function testFiberWeakReferenceStubNamedParamsResolve(): void
    {
        $fiberCtor = BuiltinParamNames::forClassMethod('Fiber::__construct');
        self::assertSame(['callback'], $fiberCtor);
        self::assertSame(
            ['callback'],
            BuiltinParamNames::paramNamesForInternalFunction('Fiber::__construct')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($fiberCtor, 'callback', 'Fiber::__construct'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('Fiber', '__construct'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('Fiber', '__construct'));

        $wrCreate = BuiltinParamNames::forClassMethod('WeakReference::create');
        self::assertSame(['object'], $wrCreate);
        self::assertSame(
            ['object'],
            BuiltinParamNames::paramNamesForInternalFunction('WeakReference::create')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($wrCreate, 'object', 'WeakReference::create'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('WeakReference', 'create'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('WeakReference', 'create'));
    }

    /** @covers issue #23685 */
    public function testDateTimeModifyStubNamedParamsResolve(): void
    {
        foreach (['DateTime::modify', 'DateTimeImmutable::modify'] as $qual) {
            $names = BuiltinParamNames::forClassMethod($qual);
            self::assertSame(['modifier'], $names, $qual);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'modifier', $qual));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'modify', $qual));
        }
    }

    /** @covers issue #23666 */
    public function testDateTimeZoneGetTransitionsStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forClassMethod('DateTimeZone::getTransitions');
        self::assertSame(['timestampBegin=', 'timestampEnd='], $names);
        self::assertSame(
            ['timestampBegin=', 'timestampEnd='],
            BuiltinParamNames::paramNamesForInternalFunction('DateTimeZone::getTransitions')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'timestampBegin', 'DateTimeZone::getTransitions'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'timestampEnd', 'DateTimeZone::getTransitions'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'timestamp_begin', 'DateTimeZone::getTransitions'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'object', 'DateTimeZone::getTransitions'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalMethod('DateTimeZone', 'getTransitions'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('DateTimeZone', 'getTransitions'));
    }

    /** @covers issue #10059 */
    public function testArrayMultisortArraySpliceNamedParameters(): void
    {
        $multisort = BuiltinParamNames::forFunction('array_multisort');
        self::assertSame(['array', 'rest'], $multisort);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($multisort, 'array', 'array_multisort'));
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('array_multisort'));

        $splice = BuiltinParamNames::forFunction('array_splice');
        self::assertSame(['array', 'offset', 'length', 'replacement'], $splice);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($splice, 'array', 'array_splice'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($splice, 'offset', 'array_splice'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($splice, 'length', 'array_splice'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($splice, 'replacement', 'array_splice'));
    }

    /** @covers issue #10047 */
    public function testArrayMapFilterReduceNamedParameters(): void
    {
        $map = BuiltinParamNames::forFunction('array_map');
        self::assertSame(['callback', 'array', 'arrays'], $map);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($map, 'callback', 'array_map'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($map, 'array', 'array_map'));
        self::assertSame(2, BuiltinParamNames::variadicParamIndexForFunction('array_map'));

        $filter = BuiltinParamNames::forFunction('array_filter');
        self::assertSame(['array', 'callback', 'mode'], $filter);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($filter, 'array', 'array_filter'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($filter, 'callback', 'array_filter'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($filter, 'mode', 'array_filter'));

        $reduce = BuiltinParamNames::forFunction('array_reduce');
        self::assertSame(['array', 'callback', 'initial'], $reduce);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($reduce, 'array', 'array_reduce'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($reduce, 'callback', 'array_reduce'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($reduce, 'initial', 'array_reduce'));
    }

    /** @covers issue #23576 — fdiv has no rounding_mode in php-src (reverts #9918) */
    public function testFdivHasNoRoundingModeNamedParameter(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $names = BuiltinParamNames::forFunction('fdiv');
            self::assertSame(['num1', 'num2'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'num1', 'fdiv'));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'num2', 'fdiv'));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'rounding_mode', 'fdiv'));
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('fdiv'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #9990 */
    public function testFpowRoundingModeNamedParameters(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $names = BuiltinParamNames::forFunction('fpow');
            self::assertSame(['num', 'exponent', 'rounding_mode'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'num', 'fpow'));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'exponent', 'fpow'));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'rounding_mode', 'fpow'));
            self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('fpow'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #10071 */
    public function testTypeProbeAutoloadNamedParamsResolve(): void
    {
        self::assertSame(['class', 'autoload'], BuiltinParamNames::forFunction('class_exists'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('class_exists'),
            'autoload',
            'class_exists'
        ));

        self::assertSame(['interface', 'autoload'], BuiltinParamNames::forFunction('interface_exists'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('interface_exists'),
            'autoload',
            'interface_exists'
        ));

        self::assertSame(['trait', 'autoload'], BuiltinParamNames::forFunction('trait_exists'));
        self::assertSame(['enum', 'autoload'], BuiltinParamNames::forFunction('enum_exists'));

        foreach (['class_parents', 'class_implements', 'class_uses', 'class_uses_recursive'] as $fn) {
            self::assertSame(['object_or_class', 'autoload'], BuiltinParamNames::forFunction($fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(
                BuiltinParamNames::forFunction($fn),
                'autoload',
                $fn
            ));
        }

        $isSubclass = BuiltinParamNames::forFunction('is_subclass_of');
        self::assertSame(['object_or_class', 'class', 'allow_string'], $isSubclass);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($isSubclass, 'allow_string', 'is_subclass_of'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($isSubclass, 'autoload', 'is_subclass_of'));

        $isA = BuiltinParamNames::forFunction('is_a');
        self::assertSame(['object_or_class', 'class', 'allow_string'], $isA);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($isA, 'allow_string', 'is_a'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($isA, 'autoload', 'is_a'));
    }

    /** @covers issue #16627 */
    public function testParseStrNamedResultParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('parse_str');
        self::assertSame(['string', 'result'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'parse_str'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'result', 'parse_str'));
    }

    /** @covers issue #17320 */
    public function testParseStrSeparatorNamedParamOnForwardProfile(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $names = BuiltinParamNames::forFunction('parse_str');
            self::assertSame(['string', 'result', 'separator'], $names);
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'separator', 'parse_str'));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    /** @covers issue #17092 */
    public function testTimersAndHttpNamedParamsResolve(): void
    {
        $gettimeofday = BuiltinParamNames::forFunction('gettimeofday');
        self::assertSame(['as_float'], $gettimeofday);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gettimeofday, 'as_float', 'gettimeofday'));

        $sleep = BuiltinParamNames::forFunction('sleep');
        self::assertSame(['seconds'], $sleep);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($sleep, 'seconds', 'sleep'));

        $usleep = BuiltinParamNames::forFunction('usleep');
        self::assertSame(['microseconds'], $usleep);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($usleep, 'microseconds', 'usleep'));

        $http = BuiltinParamNames::forFunction('http_response_code');
        self::assertSame(['response_code'], $http);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($http, 'response_code', 'http_response_code'));

        $cookie = BuiltinParamNames::forFunction('setcookie');
        self::assertSame(['name', 'value', 'expires_or_options', 'path', 'domain', 'secure', 'httponly'], $cookie);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($cookie, 'name', 'setcookie'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($cookie, 'value', 'setcookie'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($cookie, 'expires_or_options', 'setcookie'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($cookie, 'expires', 'setcookie'));

        $raw = BuiltinParamNames::forFunction('setrawcookie');
        self::assertSame(['name', 'value', 'expires_or_options', 'path', 'domain', 'secure', 'httponly'], $raw);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($raw, 'name', 'setrawcookie'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($raw, 'expires_or_options', 'setrawcookie'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($raw, 'expires', 'setrawcookie'));
    }

    /** @covers issue #17370 */
    public function testTokenGetAllFlagsNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('token_get_all');
        self::assertSame(['code', 'flags'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'code', 'token_get_all'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'token_get_all'));
    }

    /** @covers issue #17090 */
    public function testParseIniNamedParamsResolve(): void
    {
        $stringNames = BuiltinParamNames::forFunction('parse_ini_string');
        self::assertSame(['ini_string', 'process_sections', 'scanner_mode'], $stringNames);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($stringNames, 'ini_string', 'parse_ini_string'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($stringNames, 'process_sections', 'parse_ini_string'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($stringNames, 'scanner_mode', 'parse_ini_string'));

        $fileNames = BuiltinParamNames::forFunction('parse_ini_file');
        self::assertSame(['filename', 'process_sections', 'scanner_mode'], $fileNames);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($fileNames, 'filename', 'parse_ini_file'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fileNames, 'process_sections', 'parse_ini_file'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($fileNames, 'scanner_mode', 'parse_ini_file'));
    }

    /** @covers issue #23462 */
    public function testCheckdateGetdateGmdateSubstrCountZendStubNamedParams(): void
    {
        $checkdate = BuiltinParamNames::forFunction('checkdate');
        self::assertSame(['month', 'day', 'year'], $checkdate);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($checkdate, 'month', 'checkdate'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($checkdate, 'day', 'checkdate'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($checkdate, 'year', 'checkdate'));

        $getdate = BuiltinParamNames::forFunction('getdate');
        self::assertSame(['timestamp'], $getdate);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($getdate, 'timestamp', 'getdate'));

        $gmdate = BuiltinParamNames::forFunction('gmdate');
        self::assertSame(['format', 'timestamp'], $gmdate);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gmdate, 'format', 'gmdate'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($gmdate, 'timestamp', 'gmdate'));

        $substrCount = BuiltinParamNames::forFunction('substr_count');
        self::assertSame(['haystack', 'needle', 'offset', 'length'], $substrCount);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($substrCount, 'haystack', 'substr_count'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($substrCount, 'needle', 'substr_count'));
    }

    /** @covers issue #23216 */
    public function testStrtotimeZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('strtotime');
        self::assertSame(['datetime', 'baseTimestamp'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'datetime', 'strtotime'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'baseTimestamp', 'strtotime'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $time / $now)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'time', 'strtotime'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'now', 'strtotime'));
    }

    /** @covers issue #23276 */
    public function testDateCreateZendStubNamedParams(): void
    {
        foreach (['date_create', 'date_create_immutable'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['datetime', 'timezone'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'datetime', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'timezone', $fn));
        }
    }

    /** @covers issue #23598 */
    public function testStreamSelectFilterVarArrayGetHeadersZendStubNamedParams(): void
    {
        $streamSelect = BuiltinParamNames::forFunction('stream_select');
        self::assertSame(['read', 'write', 'except', 'seconds', 'microseconds'], $streamSelect);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($streamSelect, 'read', 'stream_select'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($streamSelect, 'seconds', 'stream_select'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($streamSelect, 'read_streams', 'stream_select'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($streamSelect, 'tv_sec', 'stream_select'));

        $fva = BuiltinParamNames::forFunction('filter_var_array');
        self::assertSame(['array', 'options', 'add_empty'], $fva);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($fva, 'array', 'filter_var_array'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fva, 'options', 'filter_var_array'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($fva, 'data', 'filter_var_array'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($fva, 'definition', 'filter_var_array'));

        $headers = BuiltinParamNames::forFunction('get_headers');
        self::assertSame(['url', 'associative', 'context'], $headers);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($headers, 'url', 'get_headers'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($headers, 'associative', 'get_headers'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($headers, 'context', 'get_headers'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($headers, 'format', 'get_headers'));
    }

    /** @covers issue #23446 */
    public function testDateDefaultTimezoneSetZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('date_default_timezone_set');
        self::assertSame(['timezoneId'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'timezoneId', 'date_default_timezone_set'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $timezone_identifier)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'timezone_identifier', 'date_default_timezone_set'));
    }

    /** @covers issue #23446 */
    public function testTimezoneIdentifiersListZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('timezone_identifiers_list');
        self::assertSame(['timezoneGroup', 'countryCode'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'timezoneGroup', 'timezone_identifiers_list'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'countryCode', 'timezone_identifiers_list'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $what / $country)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'what', 'timezone_identifiers_list'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'country', 'timezone_identifiers_list'));
    }

    /** @covers issue #24550 */
    public function testPhpinfoZendStubNamedFlagsParam(): void
    {
        $names = BuiltinParamNames::forFunction('phpinfo');
        self::assertSame(['flags'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'phpinfo'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $what)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'what', 'phpinfo'));
    }

    /** @covers issue #23275 */
    public function testMktimeGmmktimeZendStubNamedParams(): void
    {
        $expected = ['hour', 'minute', 'second', 'month', 'day', 'year'];
        foreach (['mktime', 'gmmktime'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame($expected, $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'hour', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'minute', $fn));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'second', $fn));
            self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'month', $fn));
            self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'day', $fn));
            self::assertSame(5, BuiltinParamNames::lookupNamedParamIndex($names, 'year', $fn));
            // Legacy InternalArgInfo names must not resolve (Zend rejects $min / $sec / $mon)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'min', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'sec', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'mon', $fn));
        }
    }

    /** @covers issue #23183 */
    public function testSubstrReplaceZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('substr_replace');
        self::assertSame(['string', 'replace', 'offset', 'length'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'substr_replace'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'replace', 'substr_replace'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'offset', 'substr_replace'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'length', 'substr_replace'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $repl / $start)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'substr_replace'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'repl', 'substr_replace'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'start', 'substr_replace'));
    }

    /** @covers issue #23204 */
    public function testStrRepeatZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('str_repeat');
        self::assertSame(['string', 'times'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'str_repeat'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'times', 'str_repeat'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $input / $mult)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'input', 'str_repeat'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'mult', 'str_repeat'));
    }

    /** @covers issue #23215 */
    public function testStrtrZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('strtr');
        self::assertSame(['string', 'from', 'to'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'strtr'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'from', 'strtr'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'to', 'strtr'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $str)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'strtr'));
    }

    /** @covers issue #23273 */
    public function testStripslashesQuotedPrintableZendStubNamedParams(): void
    {
        foreach (['stripslashes', 'quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', $fn));
        }
    }

    /** @covers issue #23217 */
    public function testStripTagsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('strip_tags');
        self::assertSame(['string', 'allowed_tags'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'strip_tags'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'allowed_tags', 'strip_tags'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $allowable_tags)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'strip_tags'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'allowable_tags', 'strip_tags'));
    }

    /** @covers issue #23226 */
    public function testUcwordsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('ucwords');
        self::assertSame(['string', 'separators'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'ucwords'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'separators', 'ucwords'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $delims)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'ucwords'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'delims', 'ucwords'));
    }

    /** @covers issue #23242 */
    public function testRangeZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('range');
        self::assertSame(['start', 'end', 'step'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'start', 'range'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'end', 'range'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'step', 'range'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $low / $high)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'low', 'range'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'high', 'range'));
    }

    /** @covers issue #23351 */
    public function testMbStrimwidthZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('mb_strimwidth');
        self::assertSame(['string', 'start', 'width', 'trim_marker', 'encoding'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'mb_strimwidth'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'start', 'mb_strimwidth'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'width', 'mb_strimwidth'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'trim_marker', 'mb_strimwidth'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'encoding', 'mb_strimwidth'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $trimmarker)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'trimmarker', 'mb_strimwidth'));
    }

    /** @covers issue #23227 */
    public function testMd5Sha1ZendStubNamedParams(): void
    {
        foreach (['md5', 'sha1'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string', 'binary'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'binary', $fn));
            // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $raw_output)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'raw_output', $fn));
        }
    }

    /** @covers issue #23257 */
    public function testBase64UrlZendStubNamedParams(): void
    {
        foreach (['base64_encode', 'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn), $fn);
            // Legacy InternalArgInfo name must not resolve (Zend rejects $str)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', $fn), $fn);
        }
    }

    /** @covers issue #23784 */
    public function testConvertUuZendStubNamedParams(): void
    {
        foreach (['convert_uuencode', 'convert_uudecode'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'data', $fn), $fn);
        }
    }

    /** @covers issue #23585 */
    public function testHashInitZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_init');
        self::assertSame(['algo', 'flags', 'key', 'options'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'algo', 'hash_init'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'hash_init'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'key', 'hash_init'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'hash_init'));
    }

    /** @covers issue #23595 */
    public function testHashPbkdf2ZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_pbkdf2');
        self::assertSame(
            ['algo', 'password', 'salt', 'iterations', 'length', 'binary', 'options'],
            $names
        );
        self::assertSame(5, BuiltinParamNames::lookupNamedParamIndex($names, 'binary', 'hash_pbkdf2'));
        self::assertSame(6, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'hash_pbkdf2'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'raw_output', 'hash_pbkdf2'));
    }

    /** @covers issue #24377 */
    public function testHashHmacFileZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_hmac_file');
        self::assertSame(['algo', 'filename', 'key', 'binary'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'algo', 'hash_hmac_file'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'filename', 'hash_hmac_file'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'key', 'hash_hmac_file'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'binary', 'hash_hmac_file'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $raw_output)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'raw_output', 'hash_hmac_file'));
    }

    /** @covers issue #24459 */
    public function testImageTypeToExtensionMimeTypeZendStubNamedParams(): void
    {
        $ext = BuiltinParamNames::forFunction('image_type_to_extension');
        self::assertSame(['image_type', 'include_dot='], $ext);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ext, 'image_type', 'image_type_to_extension'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($ext, 'include_dot', 'image_type_to_extension'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($ext, 'imagetype', 'image_type_to_extension'));

        $mime = BuiltinParamNames::forFunction('image_type_to_mime_type');
        self::assertSame(['image_type'], $mime);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($mime, 'image_type', 'image_type_to_mime_type'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($mime, 'imagetype', 'image_type_to_mime_type'));
    }

    /** @covers issue #23642 / #24568 */
    public function testInflateDeflateInitZendStubNamedParams(): void
    {
        foreach (['inflate_init', 'deflate_init'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['encoding', 'options='], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'encoding', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'options', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn), $fn);
        }
    }

    /** @covers issue #23490 */
    public function testArrayFillKeysZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('array_fill_keys');
        self::assertSame(['keys', 'value'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'keys', 'array_fill_keys'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'value', 'array_fill_keys'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $val)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'val', 'array_fill_keys'));
    }

    /** @covers issue #23461 */
    public function testUnlinkChdirUmaskFnmatchZendStubNamedParams(): void
    {
        $unlink = BuiltinParamNames::forFunction('unlink');
        self::assertSame(['filename', 'context'], $unlink);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($unlink, 'filename', 'unlink'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($unlink, 'context', 'unlink'));

        $chdir = BuiltinParamNames::forFunction('chdir');
        self::assertSame(['directory'], $chdir);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($chdir, 'directory', 'chdir'));

        $umask = BuiltinParamNames::forFunction('umask');
        self::assertSame(['mask'], $umask);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($umask, 'mask', 'umask'));

        $fnmatch = BuiltinParamNames::forFunction('fnmatch');
        self::assertSame(['pattern', 'filename', 'flags'], $fnmatch);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($fnmatch, 'pattern', 'fnmatch'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fnmatch, 'filename', 'fnmatch'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($fnmatch, 'flags', 'fnmatch'));
    }

    /** @covers issue #23492 */
    public function testGethostbynameZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('gethostbyname');
        self::assertSame(['hostname'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'hostname', 'gethostbyname'));
    }

    /** @covers issue #23491 */
    public function testCrc32ZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('crc32');
        self::assertSame(['string'], $names);
        self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction('crc32'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'crc32'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'crc32'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'crc', 'crc32'));
    }

    /** @covers issue #23335 */
    public function testStrcmpFamilyZendStubNamedParams(): void
    {
        foreach (['strcmp', 'strcasecmp'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string1', 'string2'], $names);
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string1', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'string2', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str1', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str2', $fn));
        }
        foreach (['strncmp', 'strncasecmp'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string1', 'string2', 'length'], $names);
            self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string1', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'string2', $fn));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'length', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str1', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str2', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'len', $fn));
        }
    }

    /** @covers issue #23895 */
    public function testArrayFirstLastZendStubNamedParams(): void
    {
        foreach (['array_first', 'array_last'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array'], $names);
            self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
        }
    }

    /** @covers issue #23460 */
    public function testEscapeshellZendStubNamedParams(): void
    {
        $arg = BuiltinParamNames::forFunction('escapeshellarg');
        self::assertSame(['arg'], $arg);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($arg, 'arg', 'escapeshellarg'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($arg, 'string', 'escapeshellarg'));

        $cmd = BuiltinParamNames::forFunction('escapeshellcmd');
        self::assertSame(['command'], $cmd);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($cmd, 'command', 'escapeshellcmd'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($cmd, 'str', 'escapeshellcmd'));
    }

    /** @covers issue #23206 */
    public function testChunkSplitStrSplitZendStubNamedParams(): void
    {
        $chunk = BuiltinParamNames::forFunction('chunk_split');
        self::assertSame(['string', 'length', 'separator'], $chunk);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($chunk, 'string', 'chunk_split'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($chunk, 'length', 'chunk_split'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($chunk, 'separator', 'chunk_split'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $chunklen / $ending)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($chunk, 'str', 'chunk_split'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($chunk, 'chunklen', 'chunk_split'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($chunk, 'ending', 'chunk_split'));

        $split = BuiltinParamNames::forFunction('str_split');
        self::assertSame(['string', 'length'], $split);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($split, 'string', 'str_split'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($split, 'length', 'str_split'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $split_length)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($split, 'str', 'str_split'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($split, 'split_length', 'str_split'));
    }

    /** @covers issue #23207 */
    public function testPasswordHashVerifyZendStubNamedParams(): void
    {
        $hash = BuiltinParamNames::forFunction('password_hash');
        self::assertSame(['password', 'algo', 'options'], $hash);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($hash, 'password', 'password_hash'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($hash, 'algo', 'password_hash'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($hash, 'options', 'password_hash'));

        $verify = BuiltinParamNames::forFunction('password_verify');
        self::assertSame(['password', 'hash'], $verify);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($verify, 'password', 'password_verify'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($verify, 'hash', 'password_verify'));
    }

    /** @covers issue #23240 */
    public function testChrZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('chr');
        self::assertSame(['codepoint'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'codepoint', 'chr'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $ascii)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'ascii', 'chr'));
    }

    /** @covers issue #23291 */
    public function testMbChrOrdZendStubNamedParams(): void
    {
        $chr = BuiltinParamNames::forFunction('mb_chr');
        self::assertSame(['codepoint', 'encoding'], $chr);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($chr, 'codepoint', 'mb_chr'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($chr, 'encoding', 'mb_chr'));

        $ord = BuiltinParamNames::forFunction('mb_ord');
        self::assertSame(['string', 'encoding'], $ord);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ord, 'string', 'mb_ord'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($ord, 'encoding', 'mb_ord'));

        $scrub = BuiltinParamNames::forFunction('mb_scrub');
        self::assertSame(['string', 'encoding'], $scrub);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($scrub, 'string', 'mb_scrub'));

        $split = BuiltinParamNames::forFunction('mb_str_split');
        self::assertSame(['string', 'length', 'encoding'], $split);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($split, 'string', 'mb_str_split'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($split, 'length', 'mb_str_split'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($split, 'encoding', 'mb_str_split'));
    }

    /** @covers issue #23383 */
    public function testFilterInputZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('filter_input');
        self::assertSame(['type', 'var_name', 'filter', 'options'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'type', 'filter_input'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'var_name', 'filter_input'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'filter', 'filter_input'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'filter_input'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $variable_name)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'variable_name', 'filter_input'));
    }

    /** @covers issue #23205 */
    public function testHashEqualsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_equals');
        self::assertSame(['known_string', 'user_string'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'known_string', 'hash_equals'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'user_string', 'hash_equals'));
    }

    /** @covers issue #23290 */
    public function testHashHkdfZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_hkdf');
        self::assertSame(['algo', 'key', 'length', 'info', 'salt'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'algo', 'hash_hkdf'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'key', 'hash_hkdf'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'length', 'hash_hkdf'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'info', 'hash_hkdf'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'salt', 'hash_hkdf'));
    }

    /** @covers issue #23307 */
    public function testIconvFamilyZendStubNamedParams(): void
    {
        $iconv = BuiltinParamNames::forFunction('iconv');
        self::assertSame(['from_encoding', 'to_encoding', 'string'], $iconv);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($iconv, 'from_encoding', 'iconv'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($iconv, 'to_encoding', 'iconv'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($iconv, 'string', 'iconv'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects them)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($iconv, 'in_charset', 'iconv'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($iconv, 'out_charset', 'iconv'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($iconv, 'str', 'iconv'));

        $strlen = BuiltinParamNames::forFunction('iconv_strlen');
        self::assertSame(['string', 'encoding'], $strlen);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($strlen, 'string', 'iconv_strlen'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($strlen, 'encoding', 'iconv_strlen'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($strlen, 'str', 'iconv_strlen'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($strlen, 'charset', 'iconv_strlen'));

        $substr = BuiltinParamNames::forFunction('iconv_substr');
        self::assertSame(['string', 'offset', 'length', 'encoding'], $substr);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($substr, 'string', 'iconv_substr'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($substr, 'offset', 'iconv_substr'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($substr, 'length', 'iconv_substr'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($substr, 'encoding', 'iconv_substr'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($substr, 'str', 'iconv_substr'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($substr, 'charset', 'iconv_substr'));
    }

    /** @covers issue #23192 */
    public function testCtypeZendStubTextNamedParams(): void
    {
        foreach ([
            'ctype_alnum', 'ctype_alpha', 'ctype_cntrl', 'ctype_digit', 'ctype_graph',
            'ctype_lower', 'ctype_print', 'ctype_punct', 'ctype_space', 'ctype_upper',
            'ctype_xdigit',
        ] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['text'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'text', $fn), $fn);
            // Legacy InternalArgInfo name must not resolve (Zend rejects $c)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'c', $fn), $fn);
        }
    }

    /** @covers issue #23263 */
    public function testTypeIntrospectionZendStubNamedParams(): void
    {
        self::assertSame(['value'], BuiltinParamNames::forFunction('get_debug_type'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('get_debug_type'),
            'value',
            'get_debug_type'
        ));

        foreach (['count', 'sizeof'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['value', 'mode'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'value', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'mode', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'var', $fn), $fn);
        }

        foreach ([
            'is_string', 'is_array', 'is_bool', 'is_int', 'is_integer', 'is_long',
            'is_float', 'is_double', 'is_null', 'is_object', 'is_resource',
            'is_countable', 'is_iterable', 'is_numeric', 'is_scalar',
        ] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['value'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'value', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'var', $fn), $fn);
        }

        foreach (['is_finite', 'is_infinite', 'is_nan'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['num'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'num', $fn), $fn);
        }
    }

    /** @covers issue #23241 */
    public function testStreamIoZendStubNamedParams(): void
    {
        foreach (['fclose', 'feof', 'fgetc', 'ftell', 'rewind', 'fflush'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['stream'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'stream', $fn), $fn);
            // Legacy InternalArgInfo name must not resolve (Zend rejects $fp)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'fp', $fn), $fn);
        }

        $fseek = BuiltinParamNames::forFunction('fseek');
        self::assertSame(['stream', 'offset', 'whence'], $fseek);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($fseek, 'stream', 'fseek'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fseek, 'offset', 'fseek'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($fseek, 'whence', 'fseek'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($fseek, 'fp', 'fseek'));
    }

    /** @covers issue #23401 */
    public function testGetObjectVarsAndGetClassMethodsZendStubNamedParams(): void
    {
        $gov = BuiltinParamNames::forFunction('get_object_vars');
        self::assertSame(['object'], $gov);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gov, 'object', 'get_object_vars'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $obj)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gov, 'obj', 'get_object_vars'));

        $gcm = BuiltinParamNames::forFunction('get_class_methods');
        self::assertSame(['object_or_class'], $gcm);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gcm, 'object_or_class', 'get_class_methods'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $class)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gcm, 'class', 'get_class_methods'));
    }

    /** @covers issue #23947 */
    public function testGetClassVarsZendStubNamedParams(): void
    {
        $gcv = BuiltinParamNames::forFunction('get_class_vars');
        self::assertSame(['class'], $gcv);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gcv, 'class', 'get_class_vars'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $class_name)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gcv, 'class_name', 'get_class_vars'));
    }

    /** @covers issue #23399 */
    public function testMethodExistsAndPropertyExistsZendStubNamedParams(): void
    {
        $me = BuiltinParamNames::forFunction('method_exists');
        self::assertSame(['object_or_class', 'method'], $me);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($me, 'object_or_class', 'method_exists'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($me, 'method', 'method_exists'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $object)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($me, 'object', 'method_exists'));

        $pe = BuiltinParamNames::forFunction('property_exists');
        self::assertSame(['object_or_class', 'property'], $pe);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($pe, 'object_or_class', 'property_exists'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($pe, 'property', 'property_exists'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $property_name)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($pe, 'property_name', 'property_exists'));
    }

    /** @covers issue #23435 */
    public function testFunctionExistsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('function_exists');
        self::assertSame(['function'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'function', 'function_exists'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $function_name)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'function_name', 'function_exists'));
    }

    /** @covers issue #23258 */
    public function testPutenvZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('putenv');
        self::assertSame(['assignment'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'assignment', 'putenv'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $setting)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'setting', 'putenv'));
    }

    /** @covers issue #23436 */
    public function testErrorReportingSessionNameZendStubNamedParams(): void
    {
        $er = BuiltinParamNames::forFunction('error_reporting');
        self::assertSame(['error_level'], $er);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($er, 'error_level', 'error_reporting'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $new_error_level)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($er, 'new_error_level', 'error_reporting'));

        $sn = BuiltinParamNames::forFunction('session_name');
        self::assertSame(['name'], $sn);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($sn, 'name', 'session_name'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $newname)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sn, 'newname', 'session_name'));
    }

    /** @covers issue #23846 */
    public function testSessionSetCookieParamsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('session_set_cookie_params');
        self::assertSame(['lifetime', 'path', 'domain', 'secure', 'httponly'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'lifetime', 'session_set_cookie_params'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'path', 'session_set_cookie_params'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'domain', 'session_set_cookie_params'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'secure', 'session_set_cookie_params'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'httponly', 'session_set_cookie_params'));
    }

    /** @covers issue #23402 */
    public function testTriggerErrorSessionIdZendStubNamedParams(): void
    {
        $te = BuiltinParamNames::forFunction('trigger_error');
        self::assertSame(['message', 'error_level'], $te);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($te, 'message', 'trigger_error'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($te, 'error_level', 'trigger_error'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($te, 'error_type', 'trigger_error'));

        $ue = BuiltinParamNames::forFunction('user_error');
        self::assertSame(['message', 'error_level'], $ue);

        $sid = BuiltinParamNames::forFunction('session_id');
        self::assertSame(['id'], $sid);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($sid, 'id', 'session_id'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sid, 'newid', 'session_id'));
    }

    /** @covers issue #24456 */
    public function testFuncGetArgZendStubNamedPositionParam(): void
    {
        $names = BuiltinParamNames::forFunction('func_get_arg');
        self::assertSame(['position'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'position', 'func_get_arg'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $arg_num)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'arg_num', 'func_get_arg'));
        self::assertSame(
            ['position'],
            BuiltinParamNames::paramNamesForInternalFunction('func_get_arg')
        );
    }

    /** @covers issue #23783 */
    public function testDateParseZendStubNamedParams(): void
    {
        $dp = BuiltinParamNames::forFunction('date_parse');
        self::assertSame(['datetime'], $dp);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($dp, 'datetime', 'date_parse'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($dp, 'date', 'date_parse'));

        $dpf = BuiltinParamNames::forFunction('date_parse_from_format');
        self::assertSame(['format', 'datetime'], $dpf);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($dpf, 'format', 'date_parse_from_format'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($dpf, 'datetime', 'date_parse_from_format'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($dpf, 'date', 'date_parse_from_format'));
    }

    /** @covers issue #23680 */
    public function testSplAutoloadUnregisterZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('spl_autoload_unregister');
        self::assertSame(['callback'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', 'spl_autoload_unregister'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'autoload_function', 'spl_autoload_unregister'));
    }

    /** @covers issue #23422 */
    public function testClassAliasZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('class_alias');
        self::assertSame(['class', 'alias', 'autoload'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'class', 'class_alias'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'alias', 'class_alias'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'autoload', 'class_alias'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $user_class_name / $alias_name)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'user_class_name', 'class_alias'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'alias_name', 'class_alias'));
    }

    /** @covers issue #23434 */
    public function testConstantDefinedZendStubNamedParams(): void
    {
        $constant = BuiltinParamNames::forFunction('constant');
        self::assertSame(['name'], $constant);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($constant, 'name', 'constant'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $const_name)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($constant, 'const_name', 'constant'));

        $defined = BuiltinParamNames::forFunction('defined');
        self::assertSame(['constant_name'], $defined);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($defined, 'constant_name', 'defined'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $name)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($defined, 'name', 'defined'));
    }

    /** @covers issue #23407 */
    public function testXmlWriterStubNamedParamsResolve(): void
    {
        $setIndent = BuiltinParamNames::forClassMethod('XMLWriter::setIndent');
        self::assertSame(['enable'], $setIndent);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($setIndent, 'enable', 'XMLWriter::setIndent'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($setIndent, 'indent', 'XMLWriter::setIndent'));

        $setIndentString = BuiltinParamNames::forClassMethod('XMLWriter::setIndentString');
        self::assertSame(['indentation'], $setIndentString);
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($setIndentString, 'indentstring', 'XMLWriter::setIndentString'));

        self::assertSame(['empty'], BuiltinParamNames::forClassMethod('XMLWriter::flush'));
        self::assertSame(['flush'], BuiltinParamNames::forClassMethod('XMLWriter::outputMemory'));
        self::assertSame(
            ['prefix', 'name', 'namespace', 'value'],
            BuiltinParamNames::forClassMethod('XMLWriter::writeAttributeNs')
        );
        self::assertSame(
            ['prefix', 'name', 'namespace'],
            BuiltinParamNames::forClassMethod('XMLWriter::startElementNs')
        );

        $proc = BuiltinParamNames::forFunction('xmlwriter_set_indent');
        self::assertSame(['writer', 'enable'], $proc);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($proc, 'writer', 'xmlwriter_set_indent'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($proc, 'xmlwriter', 'xmlwriter_set_indent'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($proc, 'indent', 'xmlwriter_set_indent'));
    }

    /** @covers issue #23608 */
    public function testXmlWriterProceduralStubNamedParamsResolve(): void
    {
        $start = BuiltinParamNames::forFunction('xmlwriter_start_element');
        self::assertSame(['writer', 'name'], $start);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($start, 'writer', 'xmlwriter_start_element'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($start, 'xmlwriter', 'xmlwriter_start_element'));

        $writeAttr = BuiltinParamNames::forFunction('xmlwriter_write_attribute');
        self::assertSame(['writer', 'name', 'value'], $writeAttr);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($writeAttr, 'value', 'xmlwriter_write_attribute'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($writeAttr, 'content', 'xmlwriter_write_attribute'));

        $writeEl = BuiltinParamNames::forFunction('xmlwriter_write_element');
        self::assertSame(['writer', 'name', 'content='], $writeEl);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($writeEl, 'writer', 'xmlwriter_write_element'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('xmlwriter_write_element'));

        self::assertSame(['uri'], BuiltinParamNames::forFunction('xmlwriter_open_uri'));
        self::assertSame(
            ['writer', 'qualifiedName', 'publicId=', 'systemId='],
            BuiltinParamNames::forFunction('xmlwriter_start_dtd')
        );
        self::assertSame(
            ['writer', 'name', 'content', 'isParam=', 'publicId=', 'systemId=', 'notationData='],
            BuiltinParamNames::forFunction('xmlwriter_write_dtd_entity')
        );
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('xmlwriter_start_document'));
        self::assertSame(3, BuiltinParamNames::requiredParamCountForInternalFunction('xmlwriter_write_dtd_entity'));
        self::assertSame(['writer', 'empty='], BuiltinParamNames::forFunction('xmlwriter_flush'));
        self::assertSame(['writer', 'flush='], BuiltinParamNames::forFunction('xmlwriter_output_memory'));
    }

    /** @covers issue #23645 */
    public function testFinfoOpenMimeContentTypeStubNamedParamsResolve(): void
    {
        $open = BuiltinParamNames::forFunction('finfo_open');
        self::assertSame(['flags=', 'magic_database='], $open);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($open, 'flags', 'finfo_open'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($open, 'magic_database', 'finfo_open'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($open, 'options', 'finfo_open'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($open, 'arg', 'finfo_open'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('finfo_open'));

        $mime = BuiltinParamNames::forFunction('mime_content_type');
        self::assertSame(['filename'], $mime);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($mime, 'filename', 'mime_content_type'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($mime, 'filename_or_stream', 'mime_content_type'));
    }

    /** @covers issue #23410 */
    public function testFinfoBufferFileStubNamedParamsResolve(): void
    {
        $buffer = BuiltinParamNames::forClassMethod('finfo::buffer');
        self::assertSame(['string', 'flags=', 'context='], $buffer);
        self::assertSame(
            ['string', 'flags=', 'context='],
            BuiltinParamNames::paramNamesForInternalFunction('finfo::buffer')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($buffer, 'string', 'finfo::buffer'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($buffer, 'flags', 'finfo::buffer'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('finfo', 'buffer'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalMethod('finfo', 'buffer'));

        $file = BuiltinParamNames::forClassMethod('finfo::file');
        self::assertSame(['filename', 'flags=', 'context='], $file);
        self::assertSame(
            ['filename', 'flags=', 'context='],
            BuiltinParamNames::paramNamesForInternalFunction('finfo::file')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($file, 'filename', 'finfo::file'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('finfo', 'file'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalMethod('finfo', 'file'));

        self::assertSame(['flags'], BuiltinParamNames::forClassMethod('finfo::set_flags'));
    }

    /** @covers issue #23409 */
    public function testNumberFormatterCurrencyStubNamedParamsResolve(): void
    {
        $format = BuiltinParamNames::forClassMethod('NumberFormatter::formatCurrency');
        self::assertSame(['amount', 'currency'], $format);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($format, 'amount', 'NumberFormatter::formatCurrency'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($format, 'currency', 'NumberFormatter::formatCurrency'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($format, 'num', 'NumberFormatter::formatCurrency'));

        $parse = BuiltinParamNames::forClassMethod('NumberFormatter::parseCurrency');
        self::assertSame(['string', 'currency', 'offset'], $parse);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($parse, 'string', 'NumberFormatter::parseCurrency'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($parse, 'offset', 'NumberFormatter::parseCurrency'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($parse, 'str', 'NumberFormatter::parseCurrency'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($parse, 'position', 'NumberFormatter::parseCurrency'));

        $procFormat = BuiltinParamNames::forFunction('numfmt_format_currency');
        self::assertSame(['formatter', 'amount', 'currency'], $procFormat);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($procFormat, 'amount', 'numfmt_format_currency'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($procFormat, 'value', 'numfmt_format_currency'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($procFormat, 'fmt', 'numfmt_format_currency'));

        $procParse = BuiltinParamNames::forFunction('numfmt_parse_currency');
        self::assertSame(['formatter', 'string', 'currency', 'offset'], $procParse);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($procParse, 'string', 'numfmt_parse_currency'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($procParse, 'offset', 'numfmt_parse_currency'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($procParse, 'position', 'numfmt_parse_currency'));
    }

    /** @covers issue #23455 */
    public function testSimpleXmlLoadStubNamedParamsResolve(): void
    {
        $string = BuiltinParamNames::forFunction('simplexml_load_string');
        self::assertSame(
            ['data', 'class_name', 'options', 'namespace_or_prefix', 'is_prefix'],
            $string
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($string, 'data', 'simplexml_load_string'));
        self::assertSame(
            3,
            BuiltinParamNames::lookupNamedParamIndex($string, 'namespace_or_prefix', 'simplexml_load_string')
        );
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($string, 'ns', 'simplexml_load_string'));

        $file = BuiltinParamNames::forFunction('simplexml_load_file');
        self::assertSame(
            ['filename', 'class_name', 'options', 'namespace_or_prefix', 'is_prefix'],
            $file
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($file, 'filename', 'simplexml_load_file'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($file, 'ns', 'simplexml_load_file'));
    }

    /** @covers issue #23624 */
    public function testXmlSetElementHandlerStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('xml_set_element_handler');
        self::assertSame(['parser', 'start_handler', 'end_handler'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'parser', 'xml_set_element_handler'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'start_handler', 'xml_set_element_handler'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'end_handler', 'xml_set_element_handler'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $shdl/$ehdl)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'shdl', 'xml_set_element_handler'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'ehdl', 'xml_set_element_handler'));
    }

    /** @covers issue #23946 */
    public function testXmlSetObjectStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('xml_set_object');
        self::assertSame(['parser', 'object'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'parser', 'xml_set_object'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'object', 'xml_set_object'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $obj)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'obj', 'xml_set_object'));
    }

    /** @covers issue #23342 */
    public function testGetResourceTypeZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('get_resource_type');
        self::assertSame(['resource'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'resource', 'get_resource_type'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $res)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'res', 'get_resource_type'));
    }

    /** @covers issue #23937 */
    public function testStreamSocketServerZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('stream_socket_server');
        self::assertSame(['address', 'error_code', 'error_message', 'flags', 'context'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'address', 'stream_socket_server'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'error_code', 'stream_socket_server'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'error_message', 'stream_socket_server'));
        // Legacy InternalArgInfo names must not resolve
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'localaddress', 'stream_socket_server'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'errcode', 'stream_socket_server'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'errstring', 'stream_socket_server'));
    }

    /** @covers issue #23938 */
    public function testStreamSocketAcceptGetNameZendStubNamedParams(): void
    {
        $accept = BuiltinParamNames::forFunction('stream_socket_accept');
        self::assertSame(['socket', 'timeout', 'peer_name'], $accept);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($accept, 'socket', 'stream_socket_accept'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($accept, 'peer_name', 'stream_socket_accept'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($accept, 'serverstream', 'stream_socket_accept'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($accept, 'peername', 'stream_socket_accept'));

        $getName = BuiltinParamNames::forFunction('stream_socket_get_name');
        self::assertSame(['socket', 'remote'], $getName);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($getName, 'socket', 'stream_socket_get_name'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($getName, 'remote', 'stream_socket_get_name'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($getName, 'stream', 'stream_socket_get_name'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($getName, 'want_peer', 'stream_socket_get_name'));
    }

    /** @covers issue #23939 */
    public function testStreamBufferContextZendStubNamedParams(): void
    {
        $buf = BuiltinParamNames::forFunction('stream_set_write_buffer');
        self::assertSame(['stream', 'size'], $buf);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($buf, 'stream', 'stream_set_write_buffer'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($buf, 'size', 'stream_set_write_buffer'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($buf, 'fp', 'stream_set_write_buffer'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($buf, 'buffer', 'stream_set_write_buffer'));

        $opt = BuiltinParamNames::forFunction('stream_context_set_option');
        self::assertSame(['context', 'wrapper_or_options', 'option_name', 'value'], $opt);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($opt, 'wrapper_or_options', 'stream_context_set_option'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($opt, 'option_name', 'stream_context_set_option'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($opt, 'wrappername', 'stream_context_set_option'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($opt, 'optionname', 'stream_context_set_option'));

        $params = BuiltinParamNames::forFunction('stream_context_set_params');
        self::assertSame(['context', 'params'], $params);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($params, 'params', 'stream_context_set_params'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($params, 'options', 'stream_context_set_params'));
    }

    /** @covers issue #24038 */
    public function testStrrchrStrriposNamedParamsResolve(): void
    {
        $strrchr = BuiltinParamNames::forFunction('strrchr');
        self::assertSame(['haystack', 'needle'], $strrchr);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($strrchr, 'haystack', 'strrchr'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($strrchr, 'needle', 'strrchr'));

        $strripos = BuiltinParamNames::forFunction('strripos');
        self::assertSame(['haystack', 'needle', 'offset'], $strripos);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($strripos, 'haystack', 'strripos'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($strripos, 'needle', 'strripos'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($strripos, 'offset', 'strripos'));

        // Sibling registration hole (same forFunction table as strstr/strchr)
        $stristr = BuiltinParamNames::forFunction('stristr');
        self::assertSame(['haystack', 'needle', 'before_needle'], $stristr);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($stristr, 'before_needle', 'stristr'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($stristr, 'part', 'stristr'));
    }

    /** @covers issue #23644 */
    public function testFtpConnectHostnameNamedParamsResolve(): void
    {
        foreach (['ftp_connect', 'ftp_ssl_connect'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['hostname', 'port', 'timeout'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'hostname', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'port', $fn));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'timeout', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'host', $fn));
            self::assertSame(
                ['hostname', 'port', 'timeout'],
                BuiltinParamNames::paramNamesForInternalFunction($fn)
            );
        }
    }

    /** @covers issue #23656 */
    public function testFtpLoginGetPutNamedParamsResolve(): void
    {
        $login = BuiltinParamNames::forFunction('ftp_login');
        self::assertSame(['ftp', 'username', 'password'], $login);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($login, 'ftp', 'ftp_login'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($login, 'stream', 'ftp_login'));
        self::assertSame(
            ['ftp', 'username', 'password'],
            BuiltinParamNames::paramNamesForInternalFunction('ftp_login')
        );

        $get = BuiltinParamNames::forFunction('ftp_get');
        self::assertSame(['ftp', 'local_filename', 'remote_filename', 'mode', 'offset'], $get);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($get, 'local_filename', 'ftp_get'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($get, 'offset', 'ftp_get'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($get, 'local_file', 'ftp_get'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($get, 'resume_pos', 'ftp_get'));
        self::assertSame(
            ['ftp', 'local_filename', 'remote_filename', 'mode', 'offset'],
            BuiltinParamNames::paramNamesForInternalFunction('ftp_get')
        );

        $put = BuiltinParamNames::forFunction('ftp_put');
        self::assertSame(['ftp', 'remote_filename', 'local_filename', 'mode', 'offset'], $put);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($put, 'remote_filename', 'ftp_put'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($put, 'local_filename', 'ftp_put'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($put, 'remote_file', 'ftp_put'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($put, 'startpos', 'ftp_put'));
        self::assertSame(
            ['ftp', 'remote_filename', 'local_filename', 'mode', 'offset'],
            BuiltinParamNames::paramNamesForInternalFunction('ftp_put')
        );
    }

    /** @covers issue #24365 */
    public function testOpensslDigestSignVerifyNamedParamsResolve(): void
    {
        $digest = BuiltinParamNames::forFunction('openssl_digest');
        self::assertSame(['data', 'digest_algo', 'binary'], $digest);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($digest, 'data', 'openssl_digest'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($digest, 'digest_algo', 'openssl_digest'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($digest, 'binary', 'openssl_digest'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($digest, 'method', 'openssl_digest'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($digest, 'raw_output', 'openssl_digest'));
        self::assertSame(
            ['data', 'digest_algo', 'binary'],
            BuiltinParamNames::paramNamesForInternalFunction('openssl_digest')
        );

        $sign = BuiltinParamNames::forFunction('openssl_sign');
        self::assertSame(['data', 'signature', 'private_key', 'algorithm'], $sign);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($sign, 'private_key', 'openssl_sign'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($sign, 'algorithm', 'openssl_sign'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sign, 'key', 'openssl_sign'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sign, 'method', 'openssl_sign'));

        $verify = BuiltinParamNames::forFunction('openssl_verify');
        self::assertSame(['data', 'signature', 'public_key', 'algorithm'], $verify);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($verify, 'public_key', 'openssl_verify'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($verify, 'algorithm', 'openssl_verify'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($verify, 'key', 'openssl_verify'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($verify, 'method', 'openssl_verify'));
    }

    /** @covers issue #24491 */
    public function testOpensslPkeyNewNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('openssl_pkey_new');
        self::assertSame(['options'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'openssl_pkey_new'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'configargs', 'openssl_pkey_new'));
        self::assertSame(
            ['options'],
            BuiltinParamNames::paramNamesForInternalFunction('openssl_pkey_new')
        );
    }

    /** @covers issue #24551 */
    public function testPcntlSignalNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('pcntl_signal');
        self::assertSame(['signal', 'handler', 'restart_syscalls'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'signal', 'pcntl_signal'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'handler', 'pcntl_signal'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'restart_syscalls', 'pcntl_signal'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'signo', 'pcntl_signal'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'handle', 'pcntl_signal'));
        self::assertSame(
            ['signal', 'handler', 'restart_syscalls'],
            BuiltinParamNames::paramNamesForInternalFunction('pcntl_signal')
        );
    }

    /** @covers issue #24563 */
    public function testHashUpdateFileNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('hash_update_file');
        self::assertSame(['context', 'filename', 'stream_context='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'hash_update_file'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'filename', 'hash_update_file'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'stream_context', 'hash_update_file'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'context_resource', 'hash_update_file'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('hash_update_file'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('hash_update_file'));
        self::assertSame(
            ['context', 'filename', 'stream_context='],
            BuiltinParamNames::paramNamesForInternalFunction('hash_update_file')
        );
    }

    /** @covers issue #24566 */
    public function testHashCopyNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('hash_copy');
        self::assertSame(['context'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'hash_copy'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction('hash_copy'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('hash_copy'));
        self::assertSame(['context'], BuiltinParamNames::paramNamesForInternalFunction('hash_copy'));
    }

    /** @covers issue #24591 */
    public function testClosureBindCallBindToNamedParamsResolve(): void
    {
        $bind = BuiltinParamNames::forClassMethod('Closure::bind');
        self::assertSame(['closure', 'newThis', 'newScope='], $bind);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($bind, 'closure', 'Closure::bind'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($bind, 'newThis', 'Closure::bind'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($bind, 'newScope', 'Closure::bind'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($bind, 'old', 'Closure::bind'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($bind, 'to', 'Closure::bind'));

        $call = BuiltinParamNames::forClassMethod('Closure::call');
        self::assertSame(['newThis', '...args'], $call);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($call, 'newThis', 'Closure::call'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($call, 'args', 'Closure::call'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($call, 'to', 'Closure::call'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($call, 'parameter', 'Closure::call'));
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('closure::call'));

        $bindTo = BuiltinParamNames::forClassMethod('Closure::bindTo');
        self::assertSame(['newThis', 'newScope='], $bindTo);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($bindTo, 'newThis', 'Closure::bindTo'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($bindTo, 'newScope', 'Closure::bindTo'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($bindTo, 'new', 'Closure::bindTo'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($bindTo, 'old', 'Closure::bindTo'));
    }

    /** @covers issue #24584 */
    public function testStreamContextGetOptionsNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('stream_context_get_options');
        self::assertSame(['stream_or_context'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'stream_or_context', 'stream_context_get_options'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'stream_context_get_options'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction('stream_context_get_options'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('stream_context_get_options'));
        self::assertSame(
            ['stream_or_context'],
            BuiltinParamNames::paramNamesForInternalFunction('stream_context_get_options')
        );
    }

    /** @covers issue #24492 */
    public function testOpensslPkeyExportNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('openssl_pkey_export');
        self::assertSame(['key', 'output', 'passphrase', 'options'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'key', 'openssl_pkey_export'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'output', 'openssl_pkey_export'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'passphrase', 'openssl_pkey_export'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'openssl_pkey_export'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'out', 'openssl_pkey_export'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'config_args', 'openssl_pkey_export'));
        self::assertSame(
            ['key', 'output', 'passphrase', 'options'],
            BuiltinParamNames::paramNamesForInternalFunction('openssl_pkey_export')
        );

        $toFile = BuiltinParamNames::forFunction('openssl_pkey_export_to_file');
        self::assertSame(['key', 'output_filename', 'passphrase', 'options'], $toFile);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($toFile, 'output_filename', 'openssl_pkey_export_to_file'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($toFile, 'outfilename', 'openssl_pkey_export_to_file'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($toFile, 'config_args', 'openssl_pkey_export_to_file'));
    }

    /** @covers issue #23343 */
    public function testGetimagesizeNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('getimagesize');
        self::assertSame(['filename', 'image_info='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'filename', 'getimagesize'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'image_info', 'getimagesize'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'imagefile', 'getimagesize'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'info', 'getimagesize'));
        self::assertSame(
            ['filename', 'image_info='],
            BuiltinParamNames::paramNamesForInternalFunction('getimagesize')
        );
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('getimagesize'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('getimagesize'));
    }

    /** @covers issue #23655 */
    public function testGzStreamNamedParamsResolve(): void
    {
        self::assertSame(['stream', 'length'], BuiltinParamNames::forFunction('gzread'));
        self::assertSame(['stream', 'data', 'length='], BuiltinParamNames::forFunction('gzwrite'));
        self::assertSame(['stream'], BuiltinParamNames::forFunction('gzclose'));
        self::assertSame(['data', 'max_length='], BuiltinParamNames::forFunction('gzuncompress'));

        $gzread = BuiltinParamNames::forFunction('gzread');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gzread, 'stream', 'gzread'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gzread, 'zp', 'gzread'));

        $gzwrite = BuiltinParamNames::forFunction('gzwrite');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($gzwrite, 'data', 'gzwrite'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gzwrite, 'string', 'gzwrite'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gzwrite, 'zp', 'gzwrite'));

        $gzclose = BuiltinParamNames::forFunction('gzclose');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gzclose, 'stream', 'gzclose'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gzclose, 'zp', 'gzclose'));

        $gzuncompress = BuiltinParamNames::forFunction('gzuncompress');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gzuncompress, 'data', 'gzuncompress'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($gzuncompress, 'max_length', 'gzuncompress'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gzuncompress, 'max_decoded_len', 'gzuncompress'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('gzuncompress'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('gzuncompress'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('gzwrite'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('gzwrite'));
    }

    /** @covers issue #24392 */
    public function testGzLineNamedParamsResolve(): void
    {
        self::assertSame(['stream', 'length='], BuiltinParamNames::forFunction('gzgets'));
        self::assertSame(['stream'], BuiltinParamNames::forFunction('gzgetc'));
        self::assertSame(['stream'], BuiltinParamNames::forFunction('gzeof'));
        self::assertSame(['stream', 'data', 'length='], BuiltinParamNames::forFunction('gzputs'));

        $gzgets = BuiltinParamNames::forFunction('gzgets');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gzgets, 'stream', 'gzgets'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($gzgets, 'length', 'gzgets'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gzgets, 'zp', 'gzgets'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('gzgets'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('gzgets'));

        $gzputs = BuiltinParamNames::forFunction('gzputs');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($gzputs, 'data', 'gzputs'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gzputs, 'zp', 'gzputs'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($gzputs, 'string', 'gzputs'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('gzputs'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('gzputs'));

        self::assertSame(
            ['stream', 'length='],
            BuiltinParamNames::paramNamesForInternalFunction('gzgets')
        );
        self::assertSame(
            ['stream', 'data', 'length='],
            BuiltinParamNames::paramNamesForInternalFunction('gzputs')
        );
    }

    /** @covers issue #24568 */
    public function testDeflateInflateNamedParamsResolve(): void
    {
        self::assertSame(['encoding', 'options='], BuiltinParamNames::forFunction('deflate_init'));
        self::assertSame(['encoding', 'options='], BuiltinParamNames::forFunction('inflate_init'));
        self::assertSame(['context', 'data', 'flush_mode='], BuiltinParamNames::forFunction('deflate_add'));
        self::assertSame(['context', 'data', 'flush_mode='], BuiltinParamNames::forFunction('inflate_add'));

        $deflateInit = BuiltinParamNames::forFunction('deflate_init');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($deflateInit, 'encoding', 'deflate_init'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($deflateInit, 'options', 'deflate_init'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('deflate_init'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('deflate_init'));

        $inflateAdd = BuiltinParamNames::forFunction('inflate_add');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($inflateAdd, 'context', 'inflate_add'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($inflateAdd, 'data', 'inflate_add'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($inflateAdd, 'flush_mode', 'inflate_add'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($inflateAdd, 'encoded_data', 'inflate_add'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('inflate_add'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('inflate_add'));
    }

    /** @covers issue #24641 */
    public function testMtRandOptionalNamedParams(): void
    {
        self::assertSame(['min=', 'max='], BuiltinParamNames::forFunction('mt_rand'));
        self::assertSame(
            ['min=', 'max='],
            BuiltinParamNames::paramNamesForInternalFunction('mt_rand')
        );
        $names = BuiltinParamNames::forFunction('mt_rand');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'min', 'mt_rand'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'max', 'mt_rand'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('mt_rand'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('mt_rand'));
    }

    /** @covers issue #23876 */
    public function testJsonValidateNamedParamsResolve(): void
    {
        self::assertSame(['json', 'depth=', 'flags='], BuiltinParamNames::forFunction('json_validate'));
        $names = BuiltinParamNames::forFunction('json_validate');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'json', 'json_validate'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'depth', 'json_validate'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'json_validate'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('json_validate'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('json_validate'));
    }

    /** @covers issue #24577 */
    public function testStrIncdecNamedParamsResolve(): void
    {
        foreach (['str_increment', 'str_decrement'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn), $fn);
            self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction($fn), $fn);
        }
    }
}
