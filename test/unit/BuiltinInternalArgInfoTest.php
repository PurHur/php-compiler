<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Internal builtin arginfo arity via ircmaxell/php-types (#11453). */
final class BuiltinInternalArgInfoTest extends TestCase
{
    public function testProcOpenCommandParamIsArrayOrStringUnion(): void
    {
        $info = new \PHPTypes\InternalArgInfo();
        $params = $info->functions['proc_open']['params'];
        self::assertSame('command', $params[0]['name']);
        self::assertSame('array|string', $params[0]['type']);
    }

    public function testArrayMapParamCount(): void
    {
        $this->assertSame(3, BuiltinInternalArgInfo::paramCountForFunction('array_map'));
    }

    public function testBuiltinParamNamesTakesPrecedence(): void
    {
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalFunction('strlen'));
        $this->assertSame(3, BuiltinParamNames::paramCountForInternalFunction('json_encode'));
    }

    public function testUnknownFunctionReturnsNull(): void
    {
        $this->assertNull(BuiltinInternalArgInfo::paramCountForFunction('not_a_real_builtin_xyz'));
    }

    /** Stub return labels feed ReflectionFunction::has/getReturnType for internals (#22068, #25043). */
    public function testReturnTypeLabelForInternalFreeFunctions(): void
    {
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('strlen'));
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('count'));
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('sizeof'));
        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('array_keys'));
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('is_string'));
        $this->assertNull(BuiltinInternalArgInfo::returnTypeLabelForFunction('not_a_real_builtin_xyz'));
    }

    /** Zend/zend_builtin_functions.stub.php — count/sizeof Countable|array + int mode (#25966). */
    public function testCountSizeofReflectionStubTypes(): void
    {
        foreach (['count', 'sizeof'] as $f) {
            $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
            $this->assertSame('Countable|array', BuiltinInternalArgInfo::stubParamTypeOverride($f, 0), $f);
            $this->assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride($f, 1), $f);
            $value = BuiltinInternalArgInfo::paramInfoForFunction($f, 0);
            $this->assertNotNull($value, $f);
            $this->assertSame('Countable|array', $value['type'], $f);
            $this->assertFalse($value['isOptional'], $f);
            $mode = BuiltinInternalArgInfo::paramInfoForFunction($f, 1);
            $this->assertNotNull($mode, $f);
            $this->assertSame('int', $mode['type'], $f);
            $this->assertTrue($mode['isOptional'], $f);
        }
    }

    /** Zend/zend_builtin_functions.stub.php — exit/die string|int $status = 0 : never (#26056). */
    public function testExitDieReflectionStubTypes(): void
    {
        foreach (['exit', 'die'] as $f) {
            $this->assertSame('never', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
            $this->assertSame('string|int', BuiltinInternalArgInfo::stubParamTypeOverride($f, 0), $f);
            $info = BuiltinInternalArgInfo::paramInfoForFunction($f, 0);
            $this->assertNotNull($info, $f);
            $this->assertSame('status', $info['name'], $f);
            $this->assertSame('string|int', $info['type'], $f);
            $this->assertTrue(
                BuiltinInternalDefaultValues::isAvailable($f, 0, [
                    'name' => 'status',
                    'type' => 'string|int',
                    'isOptional' => true,
                ], false),
                $f
            );
        }
    }

    /** php-src string.stub.php — InternalArgInfo omits |false (#25442). */
    public function testStrposFamilyReturnTypeIncludesFalse(): void
    {
        foreach (['strpos', 'stripos', 'strrpos', 'strripos'] as $f) {
            $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        foreach (['strstr', 'stristr'] as $f) {
            $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
    }

    /** php-src math.stub.php — InternalArgInfo float→int; Zend int|float→float (#25595). */
    public function testCeilFloorReflectionStubTypes(): void
    {
        foreach (['ceil', 'floor'] as $f) {
            $this->assertSame('float', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
            $this->assertSame('int|float', BuiltinInternalArgInfo::stubParamTypeOverride($f, 0), $f);
            $info = BuiltinInternalArgInfo::paramInfoForFunction($f, 0);
            $this->assertNotNull($info, $f);
            $this->assertSame('int|float', $info['type'], $f);
        }
    }

    /** php-src array.stub.php — InternalArgInfo return empty; Zend int|float (#25441). */
    public function testArraySumProductReturnTypeIsIntOrFloat(): void
    {
        foreach (['array_sum', 'array_product'] as $f) {
            $this->assertSame('int|float', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
    }

    /** php-src array.stub.php — InternalArgInfo return bool; Zend true (#26172). */
    public function testUsortKsortFamilyReturnTypeIsTrue(): void
    {
        foreach (['usort', 'uasort', 'uksort', 'ksort', 'krsort'] as $f) {
            $this->assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction($f), $f);
            $this->assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
    }

    /** php-src basic_functions.stub.php — InternalArgInfo return bool (missing string|) (#25472). */
    public function testHighlightFamilyReturnTypeIsStringOrBool(): void
    {
        foreach (['highlight_string', 'highlight_file', 'show_source'] as $f) {
            $this->assertSame('string|bool', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        $this->assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('substr', 2));
        $this->assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('substr_count', 3));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('preg_quote', 1));
        $this->assertSame(
            ['string', 'offset', 'length='],
            \PHPCompiler\BuiltinParamNames::forFunction('substr')
        );
    }

    /** php-src ext/fileinfo/fileinfo.stub.php — InternalArgInfo resource/char (#25471). */
    public function testFinfoFamilyReflectionStubTypes(): void
    {
        $this->assertSame('finfo|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('finfo_open'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('finfo_file'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('finfo_buffer'));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('finfo_open', 1));
        $this->assertSame('finfo', BuiltinInternalArgInfo::stubParamTypeOverride('finfo_file', 0));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('finfo_file', 1));
        $this->assertSame('finfo', BuiltinInternalArgInfo::stubParamTypeOverride('finfo_buffer', 0));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('finfo_buffer', 1));
    }

    /** php-src ext/hash/hash.stub.php — missing from InternalArgInfo (#25470). */
    public function testHashEqualsReflectionStubTypes(): void
    {
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_equals'));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('hash_equals', 0));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('hash_equals', 1));
    }

    /** php-src ext/json/json.stub.php — InternalArgInfo omits mixed / |false / ?bool (#25458). */
    public function testJsonDecodeEncodeReflectionStubTypes(): void
    {
        $this->assertSame('mixed', BuiltinInternalArgInfo::returnTypeLabelForFunction('json_decode'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('json_encode'));
        $this->assertSame('?bool', BuiltinInternalArgInfo::stubParamTypeOverride('json_decode', 1));
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('json_encode', 0));
    }

    /** php-src ext/json/json.stub.php — PHP 8.3+ json_validate; absent from InternalArgInfo (#26211). */
    public function testJsonValidateReflectionStubTypes(): void
    {
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('json_validate'));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('json_validate', 0));
        $this->assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('json_validate', 1));
        $this->assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('json_validate', 2));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('json_validate', 0);
        $this->assertNotNull($info);
        $this->assertSame('json', $info['name']);
        $this->assertSame('string', $info['type']);
        $this->assertFalse($info['isOptional']);
        $depth = BuiltinInternalArgInfo::paramInfoForFunction('json_validate', 1);
        $this->assertNotNull($depth);
        $this->assertSame('depth', $depth['name']);
        $this->assertSame('int', $depth['type']);
        $this->assertTrue($depth['isOptional']);
    }

    /** php-src ext/standard/basic_functions.stub.php — PHP 8.3+ get_object_id; absent from InternalArgInfo (#26210). */
    public function testGetObjectIdReflectionStubTypes(): void
    {
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('get_object_id'));
        $this->assertSame('object', BuiltinInternalArgInfo::stubParamTypeOverride('get_object_id', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('get_object_id', 0);
        $this->assertNotNull($info);
        $this->assertSame('object', $info['name']);
        $this->assertSame('object', $info['type']);
        $this->assertFalse($info['isOptional']);
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalFunction('get_object_id'));
        $this->assertSame(['object'], BuiltinParamNames::paramNamesForInternalFunction('get_object_id'));
    }

    /** php-src ext/curl/curl.stub.php — CurlHandle stubs; InternalArgInfo still resource/ch (#26186). */
    public function testCurlEasyReflectionStubTypes(): void
    {
        $this->assertSame('CurlHandle|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('curl_init'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('curl_close'));
        $this->assertSame('string|bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('curl_exec'));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('curl_init', 0));
        $this->assertSame('CurlHandle', BuiltinInternalArgInfo::stubParamTypeOverride('curl_setopt', 0));
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('curl_setopt', 2));
        $this->assertSame('CurlHandle', BuiltinInternalArgInfo::stubParamTypeOverride('curl_exec', 0));
        $this->assertSame('CurlHandle', BuiltinInternalArgInfo::stubParamTypeOverride('curl_close', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('curl_init', 0);
        $this->assertNotNull($info);
        $this->assertSame('url', $info['name']);
        $this->assertSame('?string', $info['type']);
        $this->assertTrue($info['isOptional']);
    }

    /** php-src string.stub.php — cost params stub-only; InternalArgInfo has string1/string2 only (#25538). */
    public function testLevenshteinCostParamTypesAreInt(): void
    {
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('levenshtein'));
        $this->assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('levenshtein', 0));
        $this->assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('levenshtein', 1));
        foreach ([2, 3, 4] as $index) {
            $this->assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('levenshtein', $index), (string) $index);
        }
    }

    /** php-src basic_functions.stub.php — no return; InternalArgInfo says array (#25508). */
    public function testStreamContextCreateHasNoReturnType(): void
    {
        $this->assertSame('', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('stream_context_create'));
        $this->assertNull(BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_context_create'));
    }

    /** php-src file.stub.php — InternalArgInfo omits |false (#25509). */
    public function testFileIoFamilyReflectionReturnUnions(): void
    {
        foreach (['file_get_contents', 'fread', 'fgets'] as $f) {
            $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        foreach (['file_put_contents', 'fwrite'] as $f) {
            $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('file_put_contents', 1));
    }

    /** php-src file.stub.php — InternalArgInfo omits |false for filestat/glob (#26185). */
    public function testFilestatGlobReflectionReturnUnions(): void
    {
        foreach (['filesize', 'filemtime'] as $f) {
            $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        foreach (['glob', 'scandir'] as $f) {
            $this->assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('realpath'));
    }

    /** php-src basic_functions.stub.php — InternalArgInfo omits |false (#26317). */
    public function testGetmyFamilyReflectionReturnUnions(): void
    {
        foreach (['getmyuid', 'getmygid', 'getmypid', 'getlastmod'] as $f) {
            $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
    }

    /** php-src file.stub.php — InternalArgInfo omits |false / ?int $length (#25750). */
    public function testStreamGetContentsReflectionTypes(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_get_contents'));
        $this->assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('stream_get_contents', 1));
    }

    /** php-src file.stub.php — InternalArgInfo omits |false (#26357, re-#23921). */
    public function testStreamGetLineReflectionReturnUnion(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_get_line'));
    }

    /** php-src file.stub.php — InternalArgInfo return int (missing |false) (#26322). */
    public function testFtellReflectionReturnUnion(): void
    {
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('ftell'));
    }

    /** php-src basic_functions.stub.php — fscanf return + mixed &...$vars (#26058). */
    public function testFscanfReflectionReturnAndVarsType(): void
    {
        $this->assertSame('array|int|false|null', BuiltinInternalArgInfo::returnTypeLabelForFunction('fscanf'));
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('fscanf', 2));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('fscanf', 2);
        $this->assertNotNull($info);
        $this->assertSame('mixed', $info['type']);
        $this->assertSame([2], BuiltinByRefParams::forFunction('fscanf'));
    }

    /** php-src zlib.stub.php — InternalArgInfo omits |false (#25511, #26342). */
    public function testGzencodeGzdecodeReflectionReturnUnions(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('gzencode'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('gzdecode'));
    }

    /** php-src zlib.stub.php — InternalArgInfo omits |false (#26342). */
    public function testGzcompressFamilyReflectionReturnUnions(): void
    {
        foreach (['gzcompress', 'gzuncompress', 'gzdeflate', 'gzinflate'] as $fn) {
            $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
        }
    }

    /** php-src base64.c / string.stub.php — InternalArgInfo omits |false (#25477). */
    public function testBase64DecodeHex2binReflectionReturnUnions(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('base64_decode'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('hex2bin'));
    }

    /** php-src ext/standard/basic_functions.stub.php — string / void (#25623). */
    public function testPregLastErrorMsgAndErrorClearLastReflectionReturns(): void
    {
        $this->assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction('preg_last_error_msg'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('error_clear_last'));
    }

    /** php-src ext/libxml/libxml.stub.php — array return; ?bool $use_errors = null (#25844). */
    public function testLibxmlErrorControlReflectionStubs(): void
    {
        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_get_errors'));
        $this->assertSame('?bool', BuiltinInternalArgInfo::stubParamTypeOverride('libxml_use_internal_errors', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('libxml_use_internal_errors', 0);
        $this->assertNotNull($info);
        $this->assertSame('use_errors', $info['name']);
        $this->assertSame('?bool', $info['type']);
        $this->assertTrue($info['isOptional']);
    }

    /** php-src ext/standard/string.stub.php — array|string|null $allowed_tags (#25594). */
    public function testStripTagsAllowedTagsReflectionUnion(): void
    {
        $this->assertSame('array|string|null', BuiltinInternalArgInfo::stubParamTypeOverride('strip_tags', 1));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('strip_tags', 1);
        $this->assertNotNull($info);
        $this->assertSame('array|string|null', $info['type']);
        $this->assertTrue($info['isOptional']);
    }

    /** php-src Zend/zend_builtin_functions.stub.php — array|false + untyped object_or_class (#25498). */
    public function testClassImplementsFamilyReflectionStubs(): void
    {
        foreach (['class_implements', 'class_parents', 'class_uses'] as $f) {
            $this->assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('class_parents', 0));
        $this->assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('class_implements', 0));
    }

    /** php-src ext/filter/filter.stub.php — filter_var_array / filter_input Reflection (#26184). */
    public function testFilterVarArrayAndFilterInputReflectionStubs(): void
    {
        $this->assertSame('array|false|null', BuiltinInternalArgInfo::returnTypeLabelForFunction('filter_var_array'));
        $this->assertSame('array|false|null', BuiltinInternalArgInfo::returnTypeLabelForFunction('filter_input_array'));
        $this->assertSame('mixed', BuiltinInternalArgInfo::returnTypeLabelForFunction('filter_input'));
        $this->assertSame('array|int', BuiltinInternalArgInfo::stubParamTypeOverride('filter_var_array', 1));
        $this->assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride('filter_var_array', 2));
        $this->assertSame('array|int', BuiltinInternalArgInfo::stubParamTypeOverride('filter_input_array', 1));
        $this->assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride('filter_input_array', 2));
        $this->assertSame('array|int', BuiltinInternalArgInfo::stubParamTypeOverride('filter_input', 3));
        $options = BuiltinInternalArgInfo::paramInfoForFunction('filter_var_array', 1);
        $this->assertNotNull($options);
        $this->assertSame('array|int', $options['type']);
        $this->assertTrue($options['isOptional']);
        $addEmpty = BuiltinInternalArgInfo::paramInfoForFunction('filter_var_array', 2);
        $this->assertNotNull($addEmpty);
        $this->assertSame('bool', $addEmpty['type']);
        $this->assertTrue($addEmpty['isOptional']);
    }

    public function testSplFileObjectSeekMethodParamCount(): void
    {
        $this->assertSame(1, BuiltinInternalArgInfo::paramCountForClassMethod('SplFileObject', 'seek'));
        $this->assertSame(1, BuiltinInternalArgInfo::requiredParamCountForClassMethod('SplFileObject', 'seek'));
    }

    /** php-src ext/spl/spl_heap.stub.php — mixed value1/value2; PriorityQueue priority1/2 (#25555). */
    public function testSplHeapCompareStubParamTypesAndNames(): void
    {
        foreach (['SplHeap', 'SplMinHeap', 'SplMaxHeap'] as $class) {
            $this->assertSame(
                ['value1', 'value2'],
                BuiltinParamNames::forClassMethod(strtolower($class).'::compare')
            );
            $this->assertSame(
                'mixed',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(strtolower($class), 'compare', 0)
            );
            $this->assertSame(
                'mixed',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(strtolower($class), 'compare', 1)
            );
        }
        $this->assertSame(
            ['priority1', 'priority2'],
            BuiltinParamNames::forClassMethod('splpriorityqueue::compare')
        );
        $this->assertSame(
            'mixed',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('splpriorityqueue', 'compare', 0)
        );
    }
}
