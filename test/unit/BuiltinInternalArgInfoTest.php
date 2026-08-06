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

    /** Zend/zend_builtin_functions.stub.php — object|string $object_or_class (InternalArgInfo empty) (#27706). */
    public function testGetClassMethodsObjectOrClassIsObjectOrString(): void
    {
        $f = 'get_class_methods';
        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction($f));
        $this->assertSame('object|string', BuiltinInternalArgInfo::stubParamTypeOverride($f, 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction($f, 0);
        $this->assertNotNull($info);
        $this->assertSame('object|string', $info['type']);
        $this->assertSame(
            ['object_or_class'],
            BuiltinParamNames::forFunction($f)
        );
    }

    /** Zend get_parent_class + SPL spl_autoload_functions Reflection stubs (#27902). */
    public function testGetParentClassAndSplAutoloadFunctionsReflectionStubs(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('get_parent_class'));
        $this->assertSame('object|string', BuiltinInternalArgInfo::stubParamTypeOverride('get_parent_class', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('get_parent_class', 0);
        $this->assertNotNull($info);
        $this->assertSame('object|string', $info['type']);
        $this->assertTrue($info['isOptional']);
        $this->assertSame(
            ['object_or_class='],
            BuiltinParamNames::forFunction('get_parent_class')
        );

        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('spl_autoload_functions'));
        $this->assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('spl_autoload_functions', 0));
    }

    /** Zend/zend_builtin_functions.stub.php — func_get_arg(): mixed; InternalArgInfo empty (#28023). */
    public function testFuncGetArgReflectionReturnIsMixed(): void
    {
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('func_get_arg'));
        $this->assertSame('mixed', BuiltinInternalArgInfo::returnTypeLabelForFunction('func_get_arg'));
        // Siblings already match Zend via InternalArgInfo.
        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('func_get_args'));
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('func_num_args'));
        $this->assertSame(['position'], BuiltinParamNames::forFunction('func_get_arg'));
    }

    /** Zend/zend_builtin_functions.stub.php — mixed $object_or_class (InternalArgInfo empty) (#26359). */
    public function testIsAIsSubclassOfObjectOrClassIsMixed(): void
    {
        foreach (['is_a', 'is_subclass_of'] as $f) {
            $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
            $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride($f, 0), $f);
            $info = BuiltinInternalArgInfo::paramInfoForFunction($f, 0);
            $this->assertNotNull($info, $f);
            // InternalArgInfo still says object_or_string; Reflection uses BuiltinParamNames object_or_class.
            $this->assertSame('object_or_string', $info['name'], $f);
            $this->assertSame('mixed', $info['type'], $f);
            $this->assertFalse($info['isOptional'], $f);
            $this->assertSame(
                ['object_or_class', 'class', 'allow_string'],
                BuiltinParamNames::forFunction($f),
                $f
            );
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

    /** php-src array.stub.php — absent from InternalArgInfo; Zend array→string|int|null (#26111). */
    public function testArrayKeyFirstLastReflectionStubTypes(): void
    {
        foreach (['array_key_first', 'array_key_last'] as $f) {
            $this->assertSame('string|int|null', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
            $this->assertSame('array', BuiltinInternalArgInfo::stubParamTypeOverride($f, 0), $f);
            $info = BuiltinInternalArgInfo::paramInfoForFunction($f, 0);
            $this->assertNotNull($info, $f);
            $this->assertSame('array', $info['type'], $f);
            $this->assertSame('array', $info['name'], $f);
        }
    }

    /** Zend gettype/get_resource_id Reflection stubs (#26376). */
    public function testGettypeGetResourceIdReflectionStubTypes(): void
    {
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('gettype', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('gettype', 0);
        $this->assertNotNull($info);
        $this->assertSame('mixed', $info['type']);
        // InternalArgInfo still says var; Reflection uses BuiltinParamNames value (#23263).
        $this->assertSame(['value'], BuiltinParamNames::forFunction('gettype'));
        $this->assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction('gettype'));

        $this->assertSame('int', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('get_resource_id'));
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('get_resource_id'));
        $this->assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('get_resource_id', 0));
        $this->assertSame(['resource'], BuiltinParamNames::forFunction('get_resource_id'));
    }

    /** Zend get_debug_type Reflection stubs (#26375). */
    public function testGetDebugTypeReflectionStubTypes(): void
    {
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('get_debug_type', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('get_debug_type', 0);
        $this->assertNotNull($info);
        $this->assertSame('mixed', $info['type']);
        $this->assertSame(['value'], BuiltinParamNames::forFunction('get_debug_type'));
        $this->assertSame('string', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('get_debug_type'));
        $this->assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction('get_debug_type'));
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

    /** php-src ext/hash/hash.stub.php — HashContext on hash_copy (#27745). */
    public function testHashCopyReflectionStubTypes(): void
    {
        $this->assertSame('HashContext', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_copy'));
        $this->assertSame('HashContext', BuiltinInternalArgInfo::stubParamTypeOverride('hash_copy', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('hash_copy', 0);
        $this->assertNotNull($info);
        $this->assertSame('context', $info['name']);
        $this->assertSame('HashContext', $info['type']);
        $this->assertFalse($info['isOptional']);
    }

    /** php-src ext/hash/hash.stub.php — HashContext on incremental hash builtins (#27737). */
    public function testHashIncrementalContextReflectionStubTypes(): void
    {
        foreach (['hash_update', 'hash_update_file', 'hash_final'] as $fn) {
            $this->assertSame('HashContext', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0), $fn);
            $info = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
            $this->assertNotNull($info, $fn);
            $this->assertSame('HashContext', $info['type'], $fn);
        }
        $this->assertSame('HashContext', BuiltinInternalArgInfo::stubParamTypeOverride('hash_update_stream', 0));
        $this->assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('hash_update_stream', 2));
        $length = BuiltinInternalArgInfo::paramInfoForFunction('hash_update_stream', 2);
        $this->assertNotNull($length);
        $this->assertSame('length', $length['name']);
        $this->assertSame('int', $length['type']);
        $this->assertTrue($length['isOptional']);
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

    /** php-src ext/mbstring/mbstring.stub.php — array|string unions + |false return (#26466). */
    public function testMbConvertEncodingReflectionStubTypes(): void
    {
        $this->assertSame('array|string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('mb_convert_encoding'));
        $this->assertSame('array|string', BuiltinInternalArgInfo::stubParamTypeOverride('mb_convert_encoding', 0));
        $this->assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('mb_convert_encoding', 1));
        $this->assertSame('array|string|null', BuiltinInternalArgInfo::stubParamTypeOverride('mb_convert_encoding', 2));
        $string = BuiltinInternalArgInfo::paramInfoForFunction('mb_convert_encoding', 0);
        $this->assertNotNull($string);
        $this->assertSame('array|string', $string['type']);
        $this->assertFalse($string['isOptional']);
        $to = BuiltinInternalArgInfo::paramInfoForFunction('mb_convert_encoding', 1);
        $this->assertNotNull($to);
        $this->assertSame('string', $to['type']);
        $this->assertFalse($to['isOptional']);
        $from = BuiltinInternalArgInfo::paramInfoForFunction('mb_convert_encoding', 2);
        $this->assertNotNull($from);
        $this->assertSame('array|string|null', $from['type']);
        $this->assertTrue($from['isOptional']);
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

    /** php-src ext/sysvshm/sysvshm.stub.php — SysvSharedMemory stubs; InternalArgInfo int/untyped (#27943). */
    public function testShmAttachReflectionStubTypes(): void
    {
        $this->assertSame('SysvSharedMemory|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('shm_attach'));
        $this->assertSame('mixed', BuiltinInternalArgInfo::returnTypeLabelForFunction('shm_get_var'));
        $this->assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('shm_attach', 1));
        $this->assertSame('SysvSharedMemory', BuiltinInternalArgInfo::stubParamTypeOverride('shm_detach', 0));
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('shm_put_var', 2));
        // InternalArgInfo still names memsize; Reflection uses BuiltinParamNames size (#24640).
        $this->assertSame(['key', 'size=', 'permissions='], BuiltinParamNames::forFunction('shm_attach'));
        $size = BuiltinInternalArgInfo::paramInfoForFunction('shm_attach', 1);
        $this->assertNotNull($size);
        $this->assertSame('?int', $size['type']);
        $this->assertTrue($size['isOptional']);
        $shm = BuiltinInternalArgInfo::paramInfoForFunction('shm_detach', 0);
        $this->assertNotNull($shm);
        $this->assertSame('SysvSharedMemory', $shm['type']);
    }

    /** php-src ext/curl/curl.stub.php — CurlShareHandle + mixed $value (#27704). */
    public function testCurlShareSetoptReflectionStubTypes(): void
    {
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('curl_share_setopt'));
        $this->assertSame('CurlShareHandle', BuiltinInternalArgInfo::stubParamTypeOverride('curl_share_setopt', 0));
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('curl_share_setopt', 2));
        $this->assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('curl_share_setopt', 1));
        // InternalArgInfo still names $sh; Reflection uses BuiltinParamNames share_handle.
        $this->assertSame(['share_handle', 'option', 'value'], BuiltinParamNames::forFunction('curl_share_setopt'));
        $share = BuiltinInternalArgInfo::paramInfoForFunction('curl_share_setopt', 0);
        $this->assertNotNull($share);
        $this->assertSame('CurlShareHandle', $share['type']);
        $value = BuiltinInternalArgInfo::paramInfoForFunction('curl_share_setopt', 2);
        $this->assertNotNull($value);
        $this->assertSame('mixed', $value['type']);
        $this->assertSame('CurlShareHandle', BuiltinInternalArgInfo::stubParamTypeOverride('curl_share_close', 0));
        $this->assertSame('CurlShareHandle', BuiltinInternalArgInfo::stubParamTypeOverride('curl_share_errno', 0));
    }

    /** php-src ext/xml/xml.stub.php — XMLParser stubs; InternalArgInfo still resource/untyped/int (#26319). */
    public function testXmlParserReflectionStubTypes(): void
    {
        $this->assertSame('XMLParser', BuiltinInternalArgInfo::returnTypeLabelForFunction('xml_parser_create'));
        $this->assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction('xml_set_object'));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('xml_parser_create', 0));
        $this->assertSame('XMLParser', BuiltinInternalArgInfo::stubParamTypeOverride('xml_set_object', 0));
        $this->assertSame('XMLParser', BuiltinInternalArgInfo::stubParamTypeOverride('xml_parse', 0));
        $createEnc = BuiltinInternalArgInfo::paramInfoForFunction('xml_parser_create', 0);
        $this->assertNotNull($createEnc);
        $this->assertSame('encoding', $createEnc['name']);
        $this->assertSame('?string', $createEnc['type']);
        $this->assertTrue($createEnc['isOptional']);
        $setParser = BuiltinInternalArgInfo::paramInfoForFunction('xml_set_object', 0);
        $this->assertNotNull($setParser);
        $this->assertSame('parser', $setParser['name']);
        $this->assertSame('XMLParser', $setParser['type']);
        $parseParser = BuiltinInternalArgInfo::paramInfoForFunction('xml_parse', 0);
        $this->assertNotNull($parseParser);
        $this->assertSame('parser', $parseParser['name']);
        $this->assertSame('XMLParser', $parseParser['type']);
    }

    /** php-src ext/xml/xml.stub.php — create_ns / into_struct; InternalArgInfo resource/sep/array (#26687). */
    public function testXmlParserCreateNsAndIntoStructReflectionStubTypes(): void
    {
        $this->assertSame('XMLParser', BuiltinInternalArgInfo::returnTypeLabelForFunction('xml_parser_create_ns'));
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('xml_parse_into_struct'));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('xml_parser_create_ns', 0));
        $this->assertSame('XMLParser', BuiltinInternalArgInfo::stubParamTypeOverride('xml_parse_into_struct', 0));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('xml_parse_into_struct', 2));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('xml_parse_into_struct', 3));
        $enc = BuiltinInternalArgInfo::paramInfoForFunction('xml_parser_create_ns', 0);
        $this->assertNotNull($enc);
        $this->assertSame('encoding', $enc['name']);
        $this->assertSame('?string', $enc['type']);
        $this->assertTrue($enc['isOptional']);
        $sep = BuiltinInternalArgInfo::paramInfoForFunction('xml_parser_create_ns', 1);
        $this->assertNotNull($sep);
        // Name comes from BuiltinParamNames (sep → separator); type stays string from InternalArgInfo.
        $this->assertSame('string', $sep['type']);
        $this->assertTrue($sep['isOptional']);
        $parser = BuiltinInternalArgInfo::paramInfoForFunction('xml_parse_into_struct', 0);
        $this->assertNotNull($parser);
        $this->assertSame('XMLParser', $parser['type']);
        $values = BuiltinInternalArgInfo::paramInfoForFunction('xml_parse_into_struct', 2);
        $this->assertNotNull($values);
        $this->assertSame('', $values['type']);
        $index = BuiltinInternalArgInfo::paramInfoForFunction('xml_parse_into_struct', 3);
        $this->assertNotNull($index);
        $this->assertSame('', $index['type']);
        $this->assertTrue($index['isOptional']);
    }

    /** php-src ext/xml/xml.stub.php — xml_set_*_handler; InternalArgInfo hdl:string / return int (#26589). */
    public function testXmlSetHandlerReflectionStubTypes(): void
    {
        $singleHandler = [
            'xml_set_character_data_handler',
            'xml_set_default_handler',
            'xml_set_end_namespace_decl_handler',
            'xml_set_external_entity_ref_handler',
            'xml_set_notation_decl_handler',
            'xml_set_processing_instruction_handler',
            'xml_set_start_namespace_decl_handler',
            'xml_set_unparsed_entity_decl_handler',
        ];
        foreach ($singleHandler as $fn) {
            $this->assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn), $fn);
            $this->assertSame('XMLParser', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0), $fn);
            $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1), $fn);
            $parser = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
            $this->assertNotNull($parser, $fn);
            $this->assertSame('XMLParser', $parser['type'], $fn);
            $handler = BuiltinInternalArgInfo::paramInfoForFunction($fn, 1);
            $this->assertNotNull($handler, $fn);
            $this->assertSame('', $handler['type'], $fn);
        }
        $this->assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction('xml_set_element_handler'));
        $this->assertSame('XMLParser', BuiltinInternalArgInfo::stubParamTypeOverride('xml_set_element_handler', 0));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('xml_set_element_handler', 1));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('xml_set_element_handler', 2));
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

    /** php-src dir.stub.php / file.stub.php / basic_functions.stub.php — missing |false (#26320, #28000). */
    public function testReaddirTempnamHostLookupReflectionReturnUnions(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('readdir'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('tempnam'));
        $this->assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('gethostbynamel'));
        $this->assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('sys_getloadavg'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('gethostname'));
    }

    /** php-src link.stub.php — InternalArgInfo return int; Zend bool (#26323). */
    public function testSymlinkReflectionReturnBool(): void
    {
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('symlink'));
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

    /** php-src basic_functions.stub.php — ini_alter alias + string|false (#26465). */
    public function testIniSetIniAlterReflectionStubTypes(): void
    {
        foreach (['ini_set', 'ini_alter'] as $fn) {
            $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn), $fn);
            $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0), $fn);
            $this->assertSame('string|int|float|bool|null', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1), $fn);
            $option = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
            $this->assertNotNull($option, $fn);
            $this->assertSame('string', $option['type'], $fn);
            $this->assertFalse($option['isOptional'], $fn);
            $value = BuiltinInternalArgInfo::paramInfoForFunction($fn, 1);
            $this->assertNotNull($value, $fn);
            $this->assertSame('string|int|float|bool|null', $value['type'], $fn);
            $this->assertFalse($value['isOptional'], $fn);
        }
        // ini_alter is absent from InternalArgInfo — names come from BuiltinParamNames (#26465).
        $alterOption = BuiltinInternalArgInfo::paramInfoForFunction('ini_alter', 0);
        $this->assertNotNull($alterOption);
        $this->assertSame('option', $alterOption['name']);
        $alterValue = BuiltinInternalArgInfo::paramInfoForFunction('ini_alter', 1);
        $this->assertNotNull($alterValue);
        $this->assertSame('value', $alterValue['name']);
    }

    /** php-src password.stub.php — absent from InternalArgInfo (#23292). */
    public function testPasswordGetInfoNeedsRehashReflectionStubTypes(): void
    {
        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('password_get_info'));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('password_get_info', 0));
        $hash = BuiltinInternalArgInfo::paramInfoForFunction('password_get_info', 0);
        $this->assertNotNull($hash);
        $this->assertSame('hash', $hash['name']);
        $this->assertSame('string', $hash['type']);
        $this->assertFalse($hash['isOptional']);

        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('password_needs_rehash'));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('password_needs_rehash', 0));
        $this->assertSame('string|int|null', BuiltinInternalArgInfo::stubParamTypeOverride('password_needs_rehash', 1));
        $this->assertSame('array', BuiltinInternalArgInfo::stubParamTypeOverride('password_needs_rehash', 2));
        $options = BuiltinInternalArgInfo::paramInfoForFunction('password_needs_rehash', 2);
        $this->assertNotNull($options);
        $this->assertSame('options', $options['name']);
        $this->assertSame('array', $options['type']);
        $this->assertTrue($options['isOptional']);
        $this->assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'password_needs_rehash',
            2,
            ['name' => 'options', 'type' => 'array', 'isOptional' => true],
            false
        ));
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

    /** php-src ext/standard/basic_functions.stub.php — void + filename="" (#27998). */
    public function testClearstatcacheReflectionStubTypes(): void
    {
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('clearstatcache'));
        $filename = BuiltinInternalArgInfo::paramInfoForFunction('clearstatcache', 1);
        $this->assertNotNull($filename);
        $this->assertSame('filename', $filename['name']);
        $this->assertTrue($filename['isOptional']);
        $this->assertTrue(BuiltinInternalDefaultValues::isAvailable('clearstatcache', 1, $filename, false));
        $clearRealpath = BuiltinInternalArgInfo::paramInfoForFunction('clearstatcache', 0);
        $this->assertNotNull($clearRealpath);
        $this->assertTrue(BuiltinInternalDefaultValues::isAvailable('clearstatcache', 0, $clearRealpath, false));
        $dest = new \PHPCompiler\VM\Variable();
        $this->assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'clearstatcache', 1, $filename));
        $this->assertSame('', $dest->toString());
    }

    /** php-src ext/pcre/php_pcre.stub.php — InternalArgInfo omits |false (#26324). */
    public function testPregGrepPregMatchAllReflectionReturnUnions(): void
    {
        $this->assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('preg_grep'));
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('preg_match_all'));
    }

    /** php-src ext/libxml/libxml.stub.php — reflection return/default parity (#25844, #28021). */
    public function testLibxmlErrorControlReflectionStubs(): void
    {
        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_get_errors'));
        $this->assertSame('LibXMLError|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_get_last_error'));
        $this->assertSame('?callable', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_get_external_entity_loader'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_clear_errors'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_set_streams_context'));
        $this->assertSame('?bool', BuiltinInternalArgInfo::stubParamTypeOverride('libxml_use_internal_errors', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('libxml_use_internal_errors', 0);
        $this->assertNotNull($info);
        $this->assertSame('use_errors', $info['name']);
        $this->assertSame('?bool', $info['type']);
        $this->assertTrue($info['isOptional']);

        $disableInfo = BuiltinInternalArgInfo::paramInfoForFunction('libxml_disable_entity_loader', 0);
        $this->assertNotNull($disableInfo);
        $this->assertTrue(BuiltinInternalDefaultValues::isAvailable('libxml_disable_entity_loader', 0, $disableInfo, false));
        $dest = new \PHPCompiler\VM\Variable();
        $this->assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'libxml_disable_entity_loader', 0, $disableInfo));
        $this->assertTrue($dest->toBool());
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
