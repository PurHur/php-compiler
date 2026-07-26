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

    /** @covers issue #10474 */
    public function testFileFlagsNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('file');
        self::assertSame(['filename', 'flags'], $names);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'file'));
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

    /** @covers issue #10027 #23224 */
    public function testTrimCharactersNamedParamResolves(): void
    {
        foreach (['trim', 'ltrim', 'rtrim'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string', 'characters'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'characters', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'mode', $fn));
        }
    }

    /** @covers issue #10637 */
    public function testCallUserFuncVariadicNamedParamMetadata(): void
    {
        $names = BuiltinParamNames::forFunction('call_user_func');
        self::assertSame(['callback'], $names);
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('call_user_func'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('call_user_func'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('call_user_func'));
        self::assertSame(['callback', 'args'], BuiltinParamNames::forFunction('call_user_func_array'));
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

    /** @covers issue #10126 */
    public function testProcOpenNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('proc_open');
        self::assertSame(['command', 'descriptor_spec', 'pipes', 'cwd', 'env', 'options'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'command', 'proc_open'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'descriptor_spec', 'proc_open'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'pipes', 'proc_open'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'cwd', 'proc_open'));
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

    /** @covers issue #11349 */
    public function testVariadicArrayBuiltinsRejectNamedParameters(): void
    {
        foreach (['array_replace', 'array_merge', 'array_replace_recursive', 'array_merge_recursive', 'pack'] as $fn) {
            self::assertTrue(BuiltinParamNames::rejectsNamedParameters($fn), $fn);
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

    /** @covers issue #9918 */
    public function testFdivRoundingModeNamedParameters(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $names = BuiltinParamNames::forFunction('fdiv');
            self::assertSame(['num1', 'num2', 'rounding_mode'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'num1', 'fdiv'));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'num2', 'fdiv'));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'rounding_mode', 'fdiv'));
            self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('fdiv'));
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
        self::assertSame(['name', 'value', 'expires', 'path', 'domain', 'secure', 'httponly'], $cookie);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($cookie, 'name', 'setcookie'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($cookie, 'value', 'setcookie'));
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
}
