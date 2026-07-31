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
        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('array_keys'));
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('is_string'));
        $this->assertNull(BuiltinInternalArgInfo::returnTypeLabelForFunction('not_a_real_builtin_xyz'));
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

    /** php-src basic_functions.stub.php — InternalArgInfo return bool (missing string|) (#25472). */
    public function testHighlightFamilyReturnTypeIsStringOrBool(): void
    {
        foreach (['highlight_string', 'highlight_file', 'show_source'] as $f) {
            $this->assertSame('string|bool', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        $this->assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('substr_count', 3));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('preg_quote', 1));
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

    /** php-src zlib.stub.php — InternalArgInfo omits |false (#25511). */
    public function testGzencodeGzdecodeReflectionReturnUnions(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('gzencode'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('gzdecode'));
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
