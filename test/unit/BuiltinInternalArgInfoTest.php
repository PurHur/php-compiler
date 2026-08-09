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

    /** ext/standard/proc_open.stub.php — untyped &$pipes, nullable optionals, no return (#27847). */
    public function testProcOpenReflectionStubTypes(): void
    {
        $this->assertNull(BuiltinInternalArgInfo::returnTypeLabelForFunction('proc_open'));
        $this->assertSame('', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('proc_open'));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('proc_open', 2));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('proc_open', 3));
        $this->assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride('proc_open', 4));
        $this->assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride('proc_open', 5));
        $pipes = BuiltinInternalArgInfo::paramInfoForFunction('proc_open', 2);
        $this->assertNotNull($pipes);
        $this->assertSame('', $pipes['type']);
        $cwd = BuiltinInternalArgInfo::paramInfoForFunction('proc_open', 3);
        $this->assertNotNull($cwd);
        $this->assertSame('?string', $cwd['type']);
        $this->assertTrue($cwd['isOptional']);
    }

    /** streamsfuncs.stub.php — crypto_method/session_stream + int|bool (#27684). */
    public function testStreamSocketEnableCryptoReflectionStubTypes(): void
    {
        $this->assertSame(
            ['stream', 'enable', 'crypto_method=', 'session_stream='],
            BuiltinParamNames::forFunction('stream_socket_enable_crypto')
        );
        $this->assertSame(
            'int|bool',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_socket_enable_crypto')
        );
        $this->assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('stream_socket_enable_crypto', 2));
        $crypto = BuiltinInternalArgInfo::paramInfoForFunction('stream_socket_enable_crypto', 2);
        $this->assertNotNull($crypto);
        $this->assertSame('?int', $crypto['type']);
        $this->assertTrue($crypto['isOptional']);
    }

    /** basic_functions.stub.php — stream_isatty(): bool (#27774). */
    public function testStreamIsattyReflectionReturnBool(): void
    {
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_isatty'));
        $this->assertSame(['stream'], BuiltinParamNames::forFunction('stream_isatty'));
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

    /** Zend/zend_builtin_functions.stub.php — gc_disable/enable void; gc_mem_caches int (#28022). */
    public function testGcControlReflectionReturnTypes(): void
    {
        $this->assertSame('void', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('gc_disable'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('gc_disable'));
        $this->assertSame('void', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('gc_enable'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('gc_enable'));
        $this->assertSame('int', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('gc_mem_caches'));
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('gc_mem_caches'));
        // Siblings already match Zend via InternalArgInfo.
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('gc_collect_cycles'));
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('gc_enabled'));
    }

    /** Zend/zend_builtin_functions.stub.php — phpversion(?string $extension = null): string|false (#28004). */
    public function testPhpversionReflectionStub(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('phpversion'));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('phpversion', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('phpversion', 0);
        $this->assertNotNull($info);
        $this->assertSame('extension', $info['name']);
        $this->assertSame('?string', $info['type']);
        $this->assertTrue($info['isOptional']);
        $this->assertTrue(
            BuiltinInternalDefaultValues::isAvailable('phpversion', 0, $info, false)
        );
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

    /** php-src calendar.stub.php — InternalArgInfo return int (missing |false) (#28780). */
    public function testUnixtojdReturnTypeIncludesFalse(): void
    {
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('unixtojd'));
    }

    /** php-src calendar.stub.php — ?int $year = null, int $mode = 0 (#28781). */
    public function testEasterDateDaysStubParamTypes(): void
    {
        foreach (['easter_date', 'easter_days'] as $f) {
            $year = BuiltinInternalArgInfo::paramInfoForFunction($f, 0);
            $mode = BuiltinInternalArgInfo::paramInfoForFunction($f, 1);
            $this->assertNotNull($year, $f);
            $this->assertNotNull($mode, $f);
            $this->assertSame('?int', $year['type'], $f);
            $this->assertSame('int', $mode['type'], $f);
            $this->assertSame('year', $year['name'], $f);
            // easter_days InternalArgInfo still says method; Reflection uses BuiltinParamNames mode (#24362).
            $this->assertTrue($year['isOptional'], $f);
            $this->assertTrue($mode['isOptional'], $f);
            $this->assertSame(['year=', 'mode='], BuiltinParamNames::forFunction($f), $f);
        }
    }

    /** php-src php_date.stub.php — date_create*_from_format / date_modify Reflection (#27773). */
    public function testDateCreateFromFormatFamilyReflectionStubs(): void
    {
        $this->assertSame('DateTime|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('date_create_from_format'));
        $this->assertSame(
            'DateTimeImmutable|false',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('date_create_immutable_from_format')
        );
        $this->assertSame('DateTime|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('date_modify'));
        foreach (['date_create_from_format', 'date_create_immutable_from_format'] as $f) {
            $this->assertSame('string', BuiltinInternalArgInfo::paramInfoForFunction($f, 0)['type'], $f);
            $this->assertSame('string', BuiltinInternalArgInfo::paramInfoForFunction($f, 1)['type'], $f);
            $tz = BuiltinInternalArgInfo::paramInfoForFunction($f, 2);
            $this->assertNotNull($tz, $f);
            $this->assertSame('?DateTimeZone', $tz['type'], $f);
            $this->assertTrue($tz['isOptional'], $f);
            $this->assertSame(['format', 'datetime', 'timezone='], BuiltinParamNames::forFunction($f), $f);
        }
        $this->assertSame('DateTime', BuiltinInternalArgInfo::paramInfoForFunction('date_modify', 0)['type']);
        $this->assertSame('string', BuiltinInternalArgInfo::paramInfoForFunction('date_modify', 1)['type']);
    }

    /** php-src php_date.stub.php — DateTimeZone::getOffset(DateTimeInterface $datetime) (#28910). */
    public function testDateTimeZoneGetOffsetReflectionParamType(): void
    {
        $this->assertSame(
            'DateTimeInterface',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('datetimezone', 'getoffset', 0)
        );
        $info = BuiltinInternalArgInfo::paramInfoForClassMethod('DateTimeZone', 'getOffset', 0);
        $this->assertNotNull($info);
        $this->assertSame('datetime', $info['name']);
        $this->assertSame('DateTimeInterface', $info['type']);
        $this->assertFalse($info['isOptional']);
    }

    /** php-src php_date.stub.php — DateTime(Immutable)::createFromInterface (#28896). */
    public function testDateTimeCreateFromInterfaceReflectionStubs(): void
    {
        foreach (['DateTime', 'DateTimeImmutable'] as $class) {
            $q = strtolower($class).'::createfrominterface';
            $this->assertSame(['object'], BuiltinParamNames::forClassMethod($q), $class);
            $this->assertSame(1, BuiltinParamNames::paramCountForInternalMethod($class, 'createFromInterface'), $class);
            $this->assertSame(
                'DateTimeInterface',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(
                    strtolower($class),
                    'createfrominterface',
                    0
                ),
                $class
            );
            $info = BuiltinInternalArgInfo::paramInfoForClassMethod($class, 'createFromInterface', 0);
            $this->assertNotNull($info, $class);
            $this->assertSame('object', $info['name'], $class);
            $this->assertSame('DateTimeInterface', $info['type'], $class);
            $this->assertFalse($info['isOptional'], $class);
        }
    }

    /** php-src php_date.stub.php — strftime/gmstrftime string|false + ?int $timestamp = null (#27981). */
    public function testStrftimeGmstrftimeReflectionStubs(): void
    {
        foreach (['strftime', 'gmstrftime'] as $fn) {
            $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn), $fn);
            $this->assertSame(['format', 'timestamp='], BuiltinParamNames::forFunction($fn), $fn);
            $ts = BuiltinInternalArgInfo::paramInfoForFunction($fn, 1);
            $this->assertNotNull($ts, $fn);
            $this->assertSame('timestamp', $ts['name'], $fn);
            $this->assertSame('?int', $ts['type'], $fn);
            $this->assertTrue($ts['isOptional'], $fn);
        }
    }

    /** php-src php_date.stub.php — timezone_open(): DateTimeZone|false (#27901). */
    public function testTimezoneOpenReflectionReturnType(): void
    {
        $this->assertSame('DateTimeZone|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('timezone_open'));
        $this->assertSame('DateTimeZone|false', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('timezone_open'));
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

    /** php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo omits return (#28223). */
    public function testRestoreExceptionHandlerReturnTypeIsTrue(): void
    {
        $this->assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('restore_exception_handler'));
        $this->assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction('restore_exception_handler'));
        $this->assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('restore_error_handler'));
        $this->assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction('restore_error_handler'));
    }

    /** php-src array.stub.php — InternalArgInfo return bool; Zend true (#26172). */
    public function testUsortKsortFamilyReturnTypeIsTrue(): void
    {
        foreach (['usort', 'uasort', 'uksort', 'ksort', 'krsort'] as $f) {
            $this->assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction($f), $f);
            $this->assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
    }

    /** Zend/zend_builtin_functions.stub.php — InternalArgInfo omits return; PHP 8.4+: true (#28222). */
    public function testTriggerErrorUserErrorReturnTypeIsTrue(): void
    {
        foreach (['trigger_error', 'user_error'] as $f) {
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
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('finfo_close'));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('finfo_open', 1));
        $this->assertSame('finfo', BuiltinInternalArgInfo::stubParamTypeOverride('finfo_close', 0));
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

    /** php-src ext/sodium/libsodium.stub.php — string &$string → void (#27630). */
    public function testSodiumMemzeroReflectionStubTypes(): void
    {
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('sodium_memzero'));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('sodium_memzero', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('sodium_memzero', 0);
        $this->assertNotNull($info);
        $this->assertSame('string', $info['name']);
        $this->assertSame('string', $info['type']);
        $this->assertFalse($info['isOptional']);
        $this->assertSame([0], BuiltinByRefParams::forFunction('sodium_memzero'));
    }

    /** php-src ext/pgsql/pgsql.stub.php — result/field/oid_only → string|int|false (#27703). */
    public function testPgFieldTableReflectionStubTypes(): void
    {
        $this->assertSame(
            ['result', 'field', 'oid_only='],
            BuiltinParamNames::forFunction('pg_field_table')
        );
        $this->assertSame('string|int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('pg_field_table'));
        $this->assertSame('?PgSql\\Result', BuiltinInternalArgInfo::stubParamTypeOverride('pg_field_table', 0));
        $result = BuiltinInternalArgInfo::paramInfoForFunction('pg_field_table', 0);
        $this->assertNotNull($result);
        $this->assertSame('?PgSql\\Result', $result['type']);
        $this->assertFalse($result['isOptional']);
        $field = BuiltinInternalArgInfo::paramInfoForFunction('pg_field_table', 1);
        $this->assertNotNull($field);
        $this->assertSame('int', $field['type']);
        $this->assertFalse($field['isOptional']);
        $oidOnly = BuiltinInternalArgInfo::paramInfoForFunction('pg_field_table', 2);
        $this->assertNotNull($oidOnly);
        $this->assertSame('bool', $oidOnly['type']);
        $this->assertTrue($oidOnly['isOptional']);
    }

    /** php-src ext/hash/hash.stub.php — InternalArgInfo return string (missing |false) (#28318). */
    public function testHashFileReflectionReturnUnion(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_file'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_hmac_file'));
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

    /**
     * php-src ext/hash/hash.stub.php — hash_update(): true under PROFILE≥8.4 (#28742).
     *
     * @runInSeparateProcess
     */
    public function testHashUpdateReflectionReturnTrueUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_update'));
    }

    /**
     * php-src ≤8.3 hash_update(): bool — InternalArgInfo when PROFILE is below 8.4 (#28742).
     *
     * @runInSeparateProcess
     */
    public function testHashUpdateReflectionReturnBoolUnderProfile83(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.3');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.3';
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_update'));
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

    /** php-src mbstring.stub.php — InternalArgInfo return int / string encoding (#28583). */
    public function testMbStrposFamilyReflectionStubTypes(): void
    {
        foreach (['mb_strpos', 'mb_strrpos', 'mb_stripos', 'mb_strripos'] as $f) {
            $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
            $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride($f, 3), $f);
            $enc = BuiltinInternalArgInfo::paramInfoForFunction($f, 3);
            $this->assertNotNull($enc, $f);
            $this->assertSame('encoding', $enc['name'], $f);
            $this->assertSame('?string', $enc['type'], $f);
            $this->assertTrue($enc['isOptional'], $f);
        }
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

    /** php-src ext/spl/spl.stub.php — spl_object_id(object $object): int (#27707, re-#24569). */
    public function testSplObjectIdReflectionStubTypes(): void
    {
        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('spl_object_id'));
        $this->assertSame('object', BuiltinInternalArgInfo::stubParamTypeOverride('spl_object_id', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('spl_object_id', 0);
        $this->assertNotNull($info);
        $this->assertSame('object', $info['name']);
        $this->assertSame('object', $info['type']);
        $this->assertFalse($info['isOptional']);
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalFunction('spl_object_id'));
        $this->assertSame(['object'], BuiltinParamNames::paramNamesForInternalFunction('spl_object_id'));
    }

    /** php-src ext/xmlwriter/php_xmlwriter.stub.php — XMLWriter|false; InternalArgInfo still resource (#28786). */
    public function testXmlWriterOpenReflectionReturnUnion(): void
    {
        $this->assertSame('XMLWriter|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('xmlwriter_open_memory'));
        $this->assertSame('XMLWriter|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('xmlwriter_open_uri'));
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

    /** php-src ext/sysvmsg/sysvmsg.stub.php — SysvMessageQueue stubs; InternalArgInfo resource/untyped (#28452). */
    public function testMsgReflectionStubTypes(): void
    {
        $this->assertSame('SysvMessageQueue|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('msg_get_queue'));
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('msg_receive'));
        $this->assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('msg_stat_queue'));
        $this->assertSame('SysvMessageQueue', BuiltinInternalArgInfo::stubParamTypeOverride('msg_send', 0));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('msg_send', 5));
        $this->assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('msg_receive', 4));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('msg_receive', 2));
        $queue = BuiltinInternalArgInfo::paramInfoForFunction('msg_remove_queue', 0);
        $this->assertNotNull($queue);
        $this->assertSame('SysvMessageQueue', $queue['type']);
    }

    /** php-src ext/sysvsem/sysvsem.stub.php — SysvSemaphore stubs; InternalArgInfo untyped (#28453). */
    public function testSemReflectionStubTypes(): void
    {
        $this->assertSame('SysvSemaphore', BuiltinInternalArgInfo::stubParamTypeOverride('sem_acquire', 0));
        $this->assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride('sem_acquire', 1));
        $this->assertSame('SysvSemaphore', BuiltinInternalArgInfo::stubParamTypeOverride('sem_release', 0));
        $this->assertSame('SysvSemaphore', BuiltinInternalArgInfo::stubParamTypeOverride('sem_remove', 0));
        $sem = BuiltinInternalArgInfo::paramInfoForFunction('sem_acquire', 0);
        $this->assertNotNull($sem);
        $this->assertSame('SysvSemaphore', $sem['type']);
        $nonBlocking = BuiltinInternalArgInfo::paramInfoForFunction('sem_acquire', 1);
        $this->assertNotNull($nonBlocking);
        $this->assertSame('bool', $nonBlocking['type']);
        $this->assertTrue($nonBlocking['isOptional']);
    }

    /** php-src ext/sockets/sockets.stub.php — Socket stubs; InternalArgInfo resource/untyped (#27854). */
    public function testSocketExportImportReflectionStubTypes(): void
    {
        $this->assertSame('Socket|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('socket_import_stream'));
        $this->assertSame('Socket|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('socket_create'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('socket_close'));
        $this->assertNull(BuiltinInternalArgInfo::returnTypeLabelForFunction('socket_export_stream'));
        $this->assertSame('Socket', BuiltinInternalArgInfo::stubParamTypeOverride('socket_export_stream', 0));
        $export = BuiltinInternalArgInfo::paramInfoForFunction('socket_export_stream', 0);
        $this->assertNotNull($export);
        $this->assertSame('Socket', $export['type']);
        $this->assertSame('socket', $export['name']);
        $this->assertSame(['socket'], BuiltinParamNames::forFunction('socket_export_stream'));
        $this->assertSame(['stream'], BuiltinParamNames::forFunction('socket_import_stream'));
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

    /** php-src ext/xml/xml.stub.php — xml_get_current_* $parser: XMLParser (#27738). */
    public function testXmlGetCurrentDiagnosticsReflectionStubTypes(): void
    {
        foreach ([
            'xml_get_current_byte_index',
            'xml_get_current_column_number',
            'xml_get_current_line_number',
        ] as $fn) {
            $this->assertSame('XMLParser', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0), $fn);
            $parser = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
            $this->assertNotNull($parser, $fn);
            $this->assertSame('parser', $parser['name'], $fn);
            $this->assertSame('XMLParser', $parser['type'], $fn);
        }
    }

    /** php-src ext/xml/xml.stub.php — xml_parser_get_option XMLParser + string|int (#27743). */
    public function testXmlParserGetOptionReflectionStubTypes(): void
    {
        $this->assertSame('string|int', BuiltinInternalArgInfo::returnTypeLabelForFunction('xml_parser_get_option'));
        $this->assertSame('XMLParser', BuiltinInternalArgInfo::stubParamTypeOverride('xml_parser_get_option', 0));
        $parser = BuiltinInternalArgInfo::paramInfoForFunction('xml_parser_get_option', 0);
        $this->assertNotNull($parser);
        $this->assertSame('parser', $parser['name']);
        $this->assertSame('XMLParser', $parser['type']);
        $option = BuiltinInternalArgInfo::paramInfoForFunction('xml_parser_get_option', 1);
        $this->assertNotNull($option);
        $this->assertSame('option', $option['name']);
        $this->assertSame('int', $option['type']);
    }

    /** php-src ext/xml/xml.stub.php — xml_parser_free XMLParser → bool (#27793). */
    public function testXmlParserFreeReflectionStubTypes(): void
    {
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('xml_parser_free'));
        $this->assertSame('XMLParser', BuiltinInternalArgInfo::stubParamTypeOverride('xml_parser_free', 0));
        $parser = BuiltinInternalArgInfo::paramInfoForFunction('xml_parser_free', 0);
        $this->assertNotNull($parser);
        $this->assertSame('parser', $parser['name']);
        $this->assertSame('XMLParser', $parser['type']);
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

    /** php-src session.stub.php — empty/absent return → bool / void (#28464). */
    public function testSessionLifecycleReflectionReturns(): void
    {
        foreach ([
            'session_write_close',
            'session_commit',
            'session_abort',
            'session_reset',
            'session_unset',
        ] as $f) {
            $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction($f), $f);
        }
        $this->assertSame(
            'void',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('session_register_shutdown')
        );
    }

    /** php-src session.stub.php — InternalArgInfo string → string|false (#27726). */
    public function testSessionEncodeReflectionReturn(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('session_encode'));
    }

    /** php-src session.stub.php — absent InternalArgInfo → int|false (#27855). */
    public function testSessionGcReflectionReturn(): void
    {
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('session_gc'));
    }

    /** php-src session.stub.php — string|false + prefix="" (#27725). */
    public function testSessionCreateIdReflectionStub(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('session_create_id'));
        $this->assertSame(['prefix='], BuiltinParamNames::forFunction('session_create_id'));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('session_create_id', 0);
        $this->assertNotNull($info);
        $this->assertSame('prefix', $info['name']);
        $this->assertSame('string', $info['type']);
        $this->assertTrue(
            BuiltinInternalDefaultValues::isAvailable('session_create_id', 0, [
                'name' => 'prefix',
                'type' => 'string',
                'isOptional' => true,
            ], false)
        );
    }

    /** php-src iconv.stub.php — InternalArgInfo return int / string encoding (#27629). */
    public function testIconvStrlenReflectionStubTypes(): void
    {
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('iconv_strlen'));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('iconv_strlen', 1));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('iconv_strlen', 1);
        $this->assertNotNull($info);
        // php-types still labels the param charset; Reflection uses BuiltinParamNames → encoding.
        $this->assertSame('?string', $info['type']);
        $this->assertTrue($info['isOptional']);
    }

    /** php-src openssl.stub.php — InternalArgInfo omits return (#28368). */
    public function testOpensslErrorStringReflectionReturnUnion(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('openssl_error_string'));
    }

    /** php-src openssl.stub.php — int $length; &$strong_result untyped (not integer / bool) (#28858). */
    public function testOpensslRandomPseudoBytesReflectionStubTypes(): void
    {
        $this->assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_random_pseudo_bytes', 0));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_random_pseudo_bytes', 1));
        $length = BuiltinInternalArgInfo::paramInfoForFunction('openssl_random_pseudo_bytes', 0);
        $this->assertNotNull($length);
        $this->assertSame('int', $length['type']);
        $this->assertFalse($length['isOptional']);
        $strong = BuiltinInternalArgInfo::paramInfoForFunction('openssl_random_pseudo_bytes', 1);
        $this->assertNotNull($strong);
        $this->assertSame('', $strong['type']);
        $this->assertTrue($strong['isOptional']);
        $this->assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction('openssl_random_pseudo_bytes'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_random_pseudo_bytes'));
    }

    /** php-src openssl.stub.php — encrypt &$tag untyped + string $aad; decrypt string $iv="" / $aad="" (#28593). */
    public function testOpensslEncryptDecryptReflectionStubTypes(): void
    {
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_encrypt', 5));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_encrypt', 6));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_decrypt', 6));

        $encTag = BuiltinInternalArgInfo::paramInfoForFunction('openssl_encrypt', 5);
        $this->assertNotNull($encTag);
        $this->assertSame('tag', ltrim($encTag['name'], '&'));
        $this->assertSame('', $encTag['type']);
        $this->assertTrue($encTag['isOptional']);
        $this->assertSame([5], BuiltinByRefParams::forFunction('openssl_encrypt'));
        $this->assertSame('tag', BuiltinParamNames::forFunction('openssl_encrypt')[5]);

        $encAad = BuiltinInternalArgInfo::paramInfoForFunction('openssl_encrypt', 6);
        $this->assertNotNull($encAad);
        $this->assertSame('aad', $encAad['name']);
        $this->assertSame('string', $encAad['type']);
        $this->assertTrue($encAad['isOptional']);

        $decIv = BuiltinInternalArgInfo::paramInfoForFunction('openssl_decrypt', 4);
        $this->assertNotNull($decIv);
        $this->assertSame('iv', $decIv['name']);
        $this->assertSame('string', $decIv['type']);
        $this->assertTrue($decIv['isOptional']);
        $this->assertTrue(
            BuiltinInternalDefaultValues::isAvailable('openssl_decrypt', 4, $decIv, false)
        );

        $decAad = BuiltinInternalArgInfo::paramInfoForFunction('openssl_decrypt', 6);
        $this->assertNotNull($decAad);
        $this->assertSame('aad', $decAad['name']);
        $this->assertSame('string', $decAad['type']);
        $this->assertTrue($decAad['isOptional']);
        $this->assertTrue(
            BuiltinInternalDefaultValues::isAvailable('openssl_decrypt', 6, $decAad, false)
        );
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

    /** php-src zlib.stub.php — InternalArgInfo return string (missing |false) (#28349). */
    public function testZlibEncodeDecodeReflectionReturnUnions(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('zlib_encode'));
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('zlib_decode'));
    }

    /** php-src readline.stub.php — InternalArgInfo return string (missing |false) (#28342). */
    public function testReadlineReflectionReturnUnion(): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('readline'));
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

    /** php-src ext/libxml/libxml.stub.php — reflection return/default parity (#25844, #28021, #27744). */
    public function testLibxmlErrorControlReflectionStubs(): void
    {
        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_get_errors'));
        $this->assertSame('LibXMLError|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_get_last_error'));
        $this->assertSame('?callable', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_get_external_entity_loader'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_clear_errors'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_set_streams_context'));
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_set_external_entity_loader'));
        $this->assertSame('?bool', BuiltinInternalArgInfo::stubParamTypeOverride('libxml_use_internal_errors', 0));
        $this->assertSame('?callable', BuiltinInternalArgInfo::stubParamTypeOverride('libxml_set_external_entity_loader', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('libxml_use_internal_errors', 0);
        $this->assertNotNull($info);
        $this->assertSame('use_errors', $info['name']);
        $this->assertSame('?bool', $info['type']);
        $this->assertTrue($info['isOptional']);

        $setLoader = BuiltinInternalArgInfo::paramInfoForFunction('libxml_set_external_entity_loader', 0);
        $this->assertNotNull($setLoader);
        $this->assertSame('resolver_function', $setLoader['name']);
        $this->assertSame('?callable', $setLoader['type']);
        $this->assertFalse($setLoader['isOptional']);

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

    /** php-src ext/dom/php_dom.stub.php — loadHTML/loadHTMLFile int $options = 0 (#28713). */
    public function testDomDocumentLoadHtmlOptionsStubParamType(): void
    {
        $this->assertSame(
            'int',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('domdocument', 'loadhtml', 1)
        );
        $this->assertSame(
            'int',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('domdocument', 'loadhtmlfile', 1)
        );
        $this->assertSame(
            'int',
            BuiltinInternalArgInfo::paramInfoForClassMethod('DOMDocument', 'loadHTML', 1)['type'] ?? null
        );
        $this->assertSame(
            'int',
            BuiltinInternalArgInfo::paramInfoForClassMethod('DOMDocument', 'loadHTMLFile', 1)['type'] ?? null
        );
    }

    /** php-src ext/zlib/zlib.stub.php — DeflateContext|false / InflateContext|false (#27627); options object|array (#28592). */
    public function testDeflateInflateInitReflectionStubTypes(): void
    {
        $this->assertSame('DeflateContext|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('deflate_init'));
        $this->assertSame('InflateContext|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('inflate_init'));
        $this->assertSame('object|array', BuiltinInternalArgInfo::stubParamTypeOverride('deflate_init', 1));
        $this->assertSame('object|array', BuiltinInternalArgInfo::stubParamTypeOverride('inflate_init', 1));
        $opts = BuiltinInternalArgInfo::paramInfoForFunction('inflate_init', 1);
        $this->assertNotNull($opts);
        $this->assertSame('options', $opts['name']);
        $this->assertSame('object|array', $opts['type']);
        $this->assertTrue($opts['isOptional']);
        $deflateOpts = BuiltinInternalArgInfo::paramInfoForFunction('deflate_init', 1);
        $this->assertNotNull($deflateOpts);
        $this->assertSame('object|array', $deflateOpts['type']);
        // Optionality for deflate_init $options is from BuiltinParamNames `options=` (#24568), not InternalArgInfo.
    }

    /**
     * ext/standard/basic_functions.stub.php — StreamBucket Reflection under PROFILE≥8.4 (#27797).
     *
     * @runInSeparateProcess
     */
    public function testStreamBucketReflectionStubsUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->assertTrue(CompilerVersion::supportsStreamBucketClass());
        $this->assertSame('StreamBucket', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_bucket_new'));
        $this->assertSame('?StreamBucket', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_bucket_make_writeable'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_bucket_append'));
        $this->assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_bucket_prepend'));
        $this->assertSame('StreamBucket', BuiltinInternalArgInfo::stubParamTypeOverride('stream_bucket_append', 1));
        $this->assertSame('StreamBucket', BuiltinInternalArgInfo::stubParamTypeOverride('stream_bucket_prepend', 1));
        $bucket = BuiltinInternalArgInfo::paramInfoForFunction('stream_bucket_append', 1);
        $this->assertNotNull($bucket);
        $this->assertSame('StreamBucket', $bucket['type']);
    }

    /**
     * Default / ≤8.3 profile: stream_bucket_new Reflection return is object (#28824).
     *
     * @runInSeparateProcess
     */
    public function testStreamBucketNewReflectionObjectOnDefaultProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        $this->assertFalse(CompilerVersion::supportsStreamBucketClass());
        $this->assertSame('object', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_bucket_new'));
    }

    /** php-src ext/xmlreader/php_xmlreader.stub.php — open/XML encoding ?string (#28712). */
    public function testXmlReaderOpenXmlEncodingReflectionStubTypes(): void
    {
        foreach (['open', 'xml'] as $method) {
            $this->assertSame(
                '?string',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('xmlreader', $method, 1),
                $method
            );
            $enc = BuiltinInternalArgInfo::paramInfoForClassMethod('XMLReader', $method, 1);
            $this->assertNotNull($enc, $method);
            // InternalArgInfo still labels encoding=string; stub override makes it ?string.
            $this->assertSame('encoding', $enc['name'], $method);
            $this->assertSame('?string', $enc['type'], $method);
            $this->assertTrue($enc['isOptional'], $method);
            $flags = BuiltinInternalArgInfo::paramInfoForClassMethod('XMLReader', $method, 2);
            $this->assertNotNull($flags, $method);
            // InternalArgInfo still says options=; BuiltinParamNames renames to flags for Reflection.
            $this->assertSame('options', $flags['name'], $method);
            $this->assertSame('int', $flags['type'], $method);
            $this->assertTrue($flags['isOptional'], $method);
        }
        $this->assertSame(
            ['uri', 'encoding=', 'flags='],
            BuiltinParamNames::forClassMethod('xmlreader::open')
        );
        $this->assertSame(
            ['source', 'encoding=', 'flags='],
            BuiltinParamNames::forClassMethod('xmlreader::xml')
        );
    }
}