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

    /** @covers issue #10474 */
    public function testFileFlagsNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('file');
        self::assertSame(['filename', 'flags'], $names);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'file'));
    }

    /** @covers issue #10644 */
    public function testMicrotimeAsFloatNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('microtime');
        self::assertSame(['as_float'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'as_float', 'microtime'));
    }

    /** @covers issue #10027 */
    public function testTrimCharactersNamedParamResolves(): void
    {
        foreach (['trim', 'ltrim', 'rtrim'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string', 'characters'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'characters', $fn));
        }
    }

    /** @covers issue #10637 */
    public function testCallUserFuncVariadicNamedParamMetadata(): void
    {
        $names = BuiltinParamNames::forFunction('call_user_func');
        self::assertSame(['callback'], $names);
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('call_user_func'));
        self::assertSame(['callback', 'args'], BuiltinParamNames::forFunction('call_user_func_array'));
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

    /** @covers issue #9524 */
    public function testWordwrapNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('wordwrap');
        self::assertSame(['string', 'width', 'break', 'cut'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'wordwrap'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'width', 'wordwrap'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'break', 'wordwrap'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'cut', 'wordwrap'));
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

    /** @covers issue #10048 */
    public function testUsortNamedCallbackParameters(): void
    {
        foreach (['usort', 'uasort', 'uksort'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array', 'callback'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', $fn));
        }
        self::assertSame(['array', 'flags'], BuiltinParamNames::forFunction('sort'));
    }
}
