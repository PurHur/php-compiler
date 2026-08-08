<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinInternalDefaultValues;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\VM\Variable;
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

    /** @covers issue #25589 (reverts #9985 glue/pieces over-accept) */
    public function testImplodeNamedSeparatorAndArrayResolve(): void
    {
        $names = BuiltinParamNames::forFunction('implode');
        // php-src string.stub.php — ?array $array = null (#24811)
        self::assertSame(['separator', 'array='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'separator', 'implode'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'array', 'implode'));
        // Zend stubs use separator/array; glue/pieces are Unknown named parameter (#25589)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'glue', 'implode'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'pieces', 'implode'));
        self::assertSame([], BuiltinParamNames::aliasesForFunction('implode'));
        self::assertSame('array|string', BuiltinInternalArgInfo::stubParamTypeOverride('implode', 0));
        self::assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride('implode', 1));
        $join = BuiltinParamNames::forFunction('join');
        self::assertSame(['separator', 'array='], $join);
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($join, 'glue', 'join'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($join, 'pieces', 'join'));
        self::assertSame([], BuiltinParamNames::aliasesForFunction('join'));
        self::assertSame('array|string', BuiltinInternalArgInfo::stubParamTypeOverride('join', 0));
        self::assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride('join', 1));
    }

    public function testHtmlspecialcharsDoubleEncodeNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('htmlspecialchars');
        self::assertSame(['string', 'flags=', 'encoding=', 'double_encode='], $names);
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'double_encode', 'htmlspecialchars'));
        $entities = BuiltinParamNames::forFunction('htmlentities');
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($entities, 'double_encode', 'htmlentities'));
    }

    /** @covers issue #24970 */
    public function testHtmlentitiesZendStubReflectionDefaults(): void
    {
        $names = BuiltinParamNames::forFunction('htmlentities');
        self::assertSame(['string', 'flags=', 'encoding=', 'double_encode='], $names);
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('htmlentities'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('htmlentities'));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('htmlentities', 2));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('htmlspecialchars', 2));
        $infoFlags = ['name' => 'flags', 'type' => 'int', 'isOptional' => true];
        $infoEncoding = ['name' => 'encoding', 'type' => '?string', 'isOptional' => true];
        $infoDouble = ['name' => 'double_encode', 'type' => 'bool', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('htmlentities', 1, $infoFlags, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('htmlentities', 2, $infoEncoding, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('htmlentities', 3, $infoDouble, false));
        $flags = new Variable();
        $encoding = new Variable();
        $double = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($flags, 'htmlentities', 1, $infoFlags));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($encoding, 'htmlentities', 2, $infoEncoding));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($double, 'htmlentities', 3, $infoDouble));
        self::assertSame(Variable::TYPE_INTEGER, $flags->type);
        self::assertSame(11, $flags->toInt());
        self::assertSame(Variable::TYPE_NULL, $encoding->type);
        self::assertSame(Variable::TYPE_BOOLEAN, $double->type);
        self::assertTrue($double->toBool());
    }

    /** @covers issue #23265 */
    public function testHtmlDecodeZendStubFlagsNamedParams(): void
    {
        $decode = BuiltinParamNames::forFunction('htmlspecialchars_decode');
        self::assertSame(['string', 'flags='], $decode);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($decode, 'flags', 'htmlspecialchars_decode'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($decode, 'quote_style', 'htmlspecialchars_decode'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('htmlspecialchars_decode'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('htmlspecialchars_decode'));

        $entity = BuiltinParamNames::forFunction('html_entity_decode');
        self::assertSame(['string', 'flags=', 'encoding='], $entity);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($entity, 'flags', 'html_entity_decode'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($entity, 'encoding', 'html_entity_decode'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($entity, 'quote_style', 'html_entity_decode'));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('html_entity_decode', 2));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('html_entity_decode'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('html_entity_decode'));

        $infoFlags = ['name' => 'flags', 'type' => 'int', 'isOptional' => true];
        $infoEncoding = ['name' => 'encoding', 'type' => '?string', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('htmlspecialchars_decode', 1, $infoFlags, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('html_entity_decode', 1, $infoFlags, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('html_entity_decode', 2, $infoEncoding, false));
        $flags = new Variable();
        $encoding = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($flags, 'htmlspecialchars_decode', 1, $infoFlags));
        self::assertSame(11, $flags->toInt());
        $flags2 = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($flags2, 'html_entity_decode', 1, $infoFlags));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($encoding, 'html_entity_decode', 2, $infoEncoding));
        self::assertSame(11, $flags2->toInt());
        self::assertSame(Variable::TYPE_NULL, $encoding->type);
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
        self::assertSame(['version1', 'version2', 'operator='], $names);
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

    /** @covers issue #11577 / #24896 */
    public function testUnpackNamedFormatStringParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('unpack');
        self::assertSame(['format', 'string', 'offset='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'format', 'unpack'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'unpack'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'offset', 'unpack'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('unpack'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('unpack'));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'unpack',
            2,
            ['name' => 'offset', 'type' => '', 'isOptional' => true],
            false
        ));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'unpack',
            2,
            ['name' => 'offset', 'type' => '', 'isOptional' => true]
        ));
        self::assertSame(0, $dest->toInt());
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

    /** @covers issue #27916 */
    public function testOpensslCipherKeyLengthReflectionStubTypes(): void
    {
        self::assertSame(
            'int|false',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('openssl_cipher_key_length')
        );
        $info = BuiltinInternalArgInfo::paramInfoForFunction('openssl_cipher_key_length', 0);
        self::assertNotNull($info);
        self::assertSame('cipher_algo', $info['name']);
        self::assertSame('string', $info['type']);
        self::assertFalse($info['isOptional']);
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
        self::assertSame(['callback', 'args'], BuiltinParamNames::forFunction('forward_static_call_array'));
        self::assertTrue(BuiltinParamNames::forwardsNamedArgsIntoVariadic('call_user_func'));
        self::assertTrue(BuiltinParamNames::forwardsNamedArgsIntoVariadic('ReflectionFunction::invoke'));
        self::assertTrue(BuiltinParamNames::forwardsNamedArgsIntoVariadic('ReflectionMethod::invoke'));
        self::assertFalse(BuiltinParamNames::forwardsNamedArgsIntoVariadic('forward_static_call'));
        self::assertFalse(BuiltinParamNames::forwardsNamedArgsIntoVariadic('forward_static_call_array'));
        self::assertFalse(BuiltinParamNames::forwardsNamedArgsIntoVariadic('max'));
        self::assertSame(0, BuiltinParamNames::variadicParamIndexForFunction('ReflectionFunction::invoke'));
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('ReflectionMethod::invoke'));
        self::assertSame(['...args='], BuiltinParamNames::forClassMethod('ReflectionFunction::invoke'));
        self::assertSame(['object', '...args='], BuiltinParamNames::forClassMethod('ReflectionMethod::invoke'));
    }

    /** @covers issue #28939 — Reflection ctor Zend stub names (php_reflection.stub.php) */
    public function testReflectionCtorNamedParameters(): void
    {
        $fn = 'ReflectionFunction::__construct';
        $cls = 'ReflectionClass::__construct';
        $meth = 'ReflectionMethod::__construct';
        $prop = 'ReflectionProperty::__construct';
        self::assertSame(['function'], BuiltinParamNames::forClassMethod($fn));
        self::assertSame(['objectOrClass'], BuiltinParamNames::forClassMethod($cls));
        self::assertSame(['objectOrMethod', 'method='], BuiltinParamNames::forClassMethod($meth));
        self::assertSame(['class', 'property'], BuiltinParamNames::forClassMethod($prop));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($fn), 'function', $fn));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($cls), 'objectOrClass', $cls));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($meth), 'objectOrMethod', $meth));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($meth), 'method', $meth));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($prop), 'property', $prop));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($fn), 'name', $fn));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($cls), 'argument', $cls));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($meth), 'class_or_method', $meth));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($prop), 'name', $prop));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionFunction', '__construct'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('ReflectionFunction', '__construct'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionClass', '__construct'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionMethod', '__construct'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('ReflectionMethod', '__construct'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionProperty', '__construct'));
    }

    /** @covers issue #26237 — stub shape callback + args (basic_functions.stub.php) */
    public function testForwardStaticCallArrayNamedParamMetadata(): void
    {
        $names = BuiltinParamNames::forFunction('forward_static_call_array');
        self::assertSame(['callback', 'args'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', 'forward_static_call_array'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'args', 'forward_static_call_array'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'function', 'forward_static_call_array'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'parameters', 'forward_static_call_array'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('forward_static_call_array'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('forward_static_call_array'));
        self::assertNull(BuiltinParamNames::variadicParamIndexForFunction('forward_static_call_array'));
    }

    /** @covers issue #23380 — stub shape callback + ...args (basic_functions.stub.php) */
    public function testRegisterShutdownFunctionNamedParamMetadata(): void
    {
        $names = BuiltinParamNames::forFunction('register_shutdown_function');
        self::assertSame(['callback', 'args'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', 'register_shutdown_function'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'args', 'register_shutdown_function'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'function', 'register_shutdown_function'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'parameter', 'register_shutdown_function'));
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('register_shutdown_function'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('register_shutdown_function'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('register_shutdown_function'));
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
        self::assertSame(['...arrays'], BuiltinParamNames::forFunction('array_merge'));
        self::assertSame(['...arrays'], BuiltinParamNames::forFunction('array_merge_recursive'));
        self::assertTrue(BuiltinParamNames::overrideEntryIsOptional('...arrays'));
        self::assertSame(['array', '...replacements'], BuiltinParamNames::forFunction('array_replace'));
        self::assertSame(['array', '...replacements'], BuiltinParamNames::forFunction('array_replace_recursive'));
        self::assertTrue(BuiltinParamNames::overrideEntryIsOptional('...replacements'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('array_replace'));
        foreach ([
            'array_udiff',
            'array_udiff_assoc',
            'array_udiff_uassoc',
            'array_uintersect',
            'array_uintersect_assoc',
            'array_uintersect_uassoc',
        ] as $fn) {
            self::assertSame(['array', '...rest'], BuiltinParamNames::forFunction($fn), $fn);
            self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction($fn), $fn.' variadic');
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn), $fn.' total');
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn), $fn.' required');
        }
        self::assertTrue(BuiltinParamNames::overrideEntryIsOptional('...rest'));
        self::assertSame('int|float', BuiltinInternalArgInfo::stubParamTypeOverride('range', 2));
        self::assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('dirname', 1));
        self::assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('restore_error_handler'));
        // php-src Zend/zend_builtin_functions.stub.php (#28223)
        self::assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('restore_exception_handler'));
        self::assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction('restore_exception_handler'));
        // Zend/zend_builtin_functions.stub.php (#28222)
        foreach (['trigger_error', 'user_error'] as $fn) {
            self::assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction($fn), $fn);
            self::assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn), $fn);
        }
        // php-src ext/standard/array.stub.php (#26172)
        foreach (['usort', 'uasort', 'uksort', 'ksort', 'krsort'] as $fn) {
            self::assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction($fn), $fn);
            self::assertSame('true', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn), $fn);
        }
        // php-src ext/standard/basic_functions.stub.php (#25623)
        self::assertSame('string', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('preg_last_error_msg'));
        self::assertSame('void', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('error_clear_last'));
        self::assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction('preg_last_error_msg'));
        self::assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('error_clear_last'));
        // php-src ext/standard/basic_functions.stub.php (#26104)
        self::assertSame('void', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('memory_reset_peak_usage'));
        self::assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('memory_reset_peak_usage'));
        // php-src ext/libxml/libxml.stub.php (#25844)
        self::assertSame('array', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('libxml_get_errors'));
        self::assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('libxml_get_errors'));
        self::assertSame('?bool', BuiltinInternalArgInfo::stubParamTypeOverride('libxml_use_internal_errors', 0));
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

    /** @covers issue #25592 (reverts #10042 input over-accept) */
    public function testArrayColumnNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('array_column');
        self::assertSame(['array', 'column_key', 'index_key'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', 'array_column'));
        // Zend stubs use array; input is Unknown named parameter (#25592)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'input', 'array_column'));
        self::assertSame([], BuiltinParamNames::aliasesForFunction('array_column'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'column_key', 'array_column'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'index_key', 'array_column'));
    }

    /** @covers issue #24569 */
    public function testSplObjectHashIdNamedParameters(): void
    {
        foreach (['spl_object_hash', 'spl_object_id'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['object'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'object', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction($fn), $fn);
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn), $fn);
        }
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(['object'], 'obj', 'spl_object_hash'));
    }

    /** @covers issue #24535 */
    public function testVfprintfNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('vfprintf');
        self::assertSame(['stream', 'format', 'values'], $names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'values', 'vfprintf'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'args', 'vfprintf'));
    }

    /** @covers issue #24488 */
    public function testStreamWrapperFilterRegisterNamedParameters(): void
    {
        $wrapper = BuiltinParamNames::forFunction('stream_wrapper_register');
        self::assertSame(['protocol', 'class', 'flags'], $wrapper);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($wrapper, 'protocol', 'stream_wrapper_register'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($wrapper, 'class', 'stream_wrapper_register'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($wrapper, 'classname', 'stream_wrapper_register'));

        $filter = BuiltinParamNames::forFunction('stream_filter_register');
        self::assertSame(['filter_name', 'class'], $filter);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($filter, 'filter_name', 'stream_filter_register'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($filter, 'class', 'stream_filter_register'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($filter, 'filtername', 'stream_filter_register'));
    }

    /** @covers issue #24534 */
    public function testFtruncateNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('ftruncate');
        self::assertSame(['stream', 'size'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'stream', 'ftruncate'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'size', 'ftruncate'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'fp', 'ftruncate'));
    }

    /** @covers issue #24489 */
    public function testGetResourceIdNamedParameter(): void
    {
        $names = BuiltinParamNames::forFunction('get_resource_id');
        self::assertSame(['resource'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'resource', 'get_resource_id'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction('get_resource_id'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('get_resource_id'));
    }

    /** @covers issue #24609 */
    public function testStreamIsattyNamedParameter(): void
    {
        $names = BuiltinParamNames::forFunction('stream_isatty');
        self::assertSame(['stream'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'stream', 'stream_isatty'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction('stream_isatty'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('stream_isatty'));
    }

    /** @covers issue #23658 */
    public function testStreamMetaBlockingFilterTokenZendStubNamedParams(): void
    {
        $meta = BuiltinParamNames::forFunction('stream_get_meta_data');
        self::assertSame(['stream'], $meta);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($meta, 'stream', 'stream_get_meta_data'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($meta, 'fp', 'stream_get_meta_data'));
        self::assertSame(['stream'], BuiltinParamNames::forFunction('socket_get_status'));

        $blocking = BuiltinParamNames::forFunction('stream_set_blocking');
        self::assertSame(['stream', 'enable'], $blocking);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($blocking, 'stream', 'stream_set_blocking'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($blocking, 'enable', 'stream_set_blocking'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($blocking, 'socket', 'stream_set_blocking'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($blocking, 'mode', 'stream_set_blocking'));
        self::assertSame(['stream', 'enable'], BuiltinParamNames::forFunction('socket_set_blocking'));

        $filterId = BuiltinParamNames::forFunction('filter_id');
        self::assertSame(['name'], $filterId);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($filterId, 'name', 'filter_id'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($filterId, 'filtername', 'filter_id'));

        $tokenName = BuiltinParamNames::forFunction('token_name');
        self::assertSame(['id'], $tokenName);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($tokenName, 'id', 'token_name'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($tokenName, 'type', 'token_name'));
    }

    /** @covers issue #24610 — php-src ext/sysvsem/sysvsem.stub.php */
    public function testSemAcquireReleaseRemoveZendStubNamedParams(): void
    {
        $acquire = BuiltinParamNames::forFunction('sem_acquire');
        self::assertSame(['semaphore', 'non_blocking='], $acquire);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($acquire, 'semaphore', 'sem_acquire'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($acquire, 'non_blocking', 'sem_acquire'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($acquire, 'id', 'sem_acquire'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($acquire, 'nowait', 'sem_acquire'));
        self::assertSame(
            ['semaphore', 'non_blocking='],
            BuiltinParamNames::paramNamesForInternalFunction('sem_acquire')
        );
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('sem_acquire'));

        foreach (['sem_release', 'sem_remove'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['semaphore'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'semaphore', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'id', $fn), $fn);
            self::assertSame(['semaphore'], BuiltinParamNames::paramNamesForInternalFunction($fn), $fn);
        }
    }

    /** @covers issue #24664 — php-src ext/mysqli/mysqli.stub.php */
    public function testMysqliQueryPrepareEscapeZendStubNamedParams(): void
    {
        $query = BuiltinParamNames::forFunction('mysqli_query');
        self::assertSame(['mysql', 'query', 'result_mode='], $query);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($query, 'mysql', 'mysqli_query'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($query, 'query', 'mysqli_query'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($query, 'result_mode', 'mysqli_query'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($query, 'link', 'mysqli_query'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($query, 'resultmode', 'mysqli_query'));
        self::assertSame(
            ['mysql', 'query', 'result_mode='],
            BuiltinParamNames::paramNamesForInternalFunction('mysqli_query')
        );
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('mysqli_query'));

        $prepare = BuiltinParamNames::forFunction('mysqli_prepare');
        self::assertSame(['mysql', 'query'], $prepare);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($prepare, 'mysql', 'mysqli_prepare'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($prepare, 'query', 'mysqli_prepare'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($prepare, 'link', 'mysqli_prepare'));
        self::assertSame(['mysql', 'query'], BuiltinParamNames::paramNamesForInternalFunction('mysqli_prepare'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('mysqli_prepare'));

        $escape = BuiltinParamNames::forFunction('mysqli_real_escape_string');
        self::assertSame(['mysql', 'string'], $escape);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($escape, 'mysql', 'mysqli_real_escape_string'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($escape, 'string', 'mysqli_real_escape_string'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($escape, 'link', 'mysqli_real_escape_string'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($escape, 'escapestr', 'mysqli_real_escape_string'));
        self::assertSame(
            ['mysql', 'string'],
            BuiltinParamNames::paramNamesForInternalFunction('mysqli_real_escape_string')
        );
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('mysqli_real_escape_string'));
    }

    /** @covers issue #24640 — php-src ext/sysvshm/sysvshm.stub.php */
    public function testShmAttachPutVarZendStubNamedParams(): void
    {
        $attach = BuiltinParamNames::forFunction('shm_attach');
        self::assertSame(['key', 'size=', 'permissions='], $attach);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($attach, 'key', 'shm_attach'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($attach, 'size', 'shm_attach'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($attach, 'permissions', 'shm_attach'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($attach, 'memsize', 'shm_attach'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($attach, 'perm', 'shm_attach'));
        self::assertSame(
            ['key', 'size=', 'permissions='],
            BuiltinParamNames::paramNamesForInternalFunction('shm_attach')
        );
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('shm_attach'));

        $put = BuiltinParamNames::forFunction('shm_put_var');
        self::assertSame(['shm', 'key', 'value'], $put);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($put, 'shm', 'shm_put_var'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($put, 'key', 'shm_put_var'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($put, 'value', 'shm_put_var'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($put, 'shm_identifier', 'shm_put_var'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($put, 'variable_key', 'shm_put_var'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($put, 'variable', 'shm_put_var'));

        foreach (['shm_detach', 'shm_remove'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['shm'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'shm', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'shm_identifier', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'id', $fn), $fn);
        }

        foreach (['shm_get_var', 'shm_has_var', 'shm_remove_var'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['shm', 'key'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'shm', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'key', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'id', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'variable_key', $fn), $fn);
        }
    }

    /** @covers issue #24391 — php-src ext/shmop/shmop.stub.php */
    public function testShmopOpenReadWriteZendStubNamedParams(): void
    {
        $open = BuiltinParamNames::forFunction('shmop_open');
        self::assertSame(['key', 'mode', 'permissions', 'size'], $open);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($open, 'key', 'shmop_open'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($open, 'mode', 'shmop_open'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($open, 'permissions', 'shmop_open'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($open, 'size', 'shmop_open'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($open, 'flags', 'shmop_open'));
        self::assertSame(
            ['key', 'mode', 'permissions', 'size'],
            BuiltinParamNames::paramNamesForInternalFunction('shmop_open')
        );

        $read = BuiltinParamNames::forFunction('shmop_read');
        self::assertSame(['shmop', 'offset', 'size'], $read);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($read, 'shmop', 'shmop_read'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($read, 'offset', 'shmop_read'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($read, 'size', 'shmop_read'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($read, 'shmid', 'shmop_read'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($read, 'start', 'shmop_read'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($read, 'count', 'shmop_read'));

        $write = BuiltinParamNames::forFunction('shmop_write');
        self::assertSame(['shmop', 'data', 'offset'], $write);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($write, 'shmop', 'shmop_write'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($write, 'shmid', 'shmop_write'));

        foreach (['shmop_size', 'shmop_close', 'shmop_delete'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['shmop'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'shmop', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'shmid', $fn), $fn);
        }
    }

    /** @covers issue #26117 */
    public function testFtokZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('ftok');
        self::assertSame(['filename', 'project_id'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'filename', 'ftok'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'project_id', 'ftok'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects pathname/proj)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'pathname', 'ftok'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'proj', 'ftok'));
        self::assertSame(
            ['filename', 'project_id'],
            BuiltinParamNames::paramNamesForInternalFunction('ftok')
        );
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('ftok'));
    }

    /** @covers issue #24373 */
    public function testSocketBindConnectReadWriteSetOptionZendStubNamedParams(): void
    {
        $bind = BuiltinParamNames::forFunction('socket_bind');
        self::assertSame(['socket', 'address', 'port='], $bind);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($bind, 'socket', 'socket_bind'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($bind, 'address', 'socket_bind'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($bind, 'addr', 'socket_bind'));
        self::assertSame(['socket', 'address', 'port='], BuiltinParamNames::forFunction('socket_connect'));
        self::assertSame(
            ['socket', 'address', 'port='],
            BuiltinParamNames::paramNamesForInternalFunction('socket_bind')
        );

        $read = BuiltinParamNames::forFunction('socket_read');
        self::assertSame(['socket', 'length', 'mode='], $read);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($read, 'mode', 'socket_read'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($read, 'type', 'socket_read'));

        $write = BuiltinParamNames::forFunction('socket_write');
        self::assertSame(['socket', 'data', 'length='], $write);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($write, 'data', 'socket_write'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($write, 'buf', 'socket_write'));

        $setopt = BuiltinParamNames::forFunction('socket_set_option');
        self::assertSame(['socket', 'level', 'option', 'value'], $setopt);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($setopt, 'option', 'socket_set_option'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($setopt, 'value', 'socket_set_option'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($setopt, 'optname', 'socket_set_option'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($setopt, 'optval', 'socket_set_option'));
        self::assertSame(['socket', 'level', 'option', 'value'], BuiltinParamNames::forFunction('socket_setopt'));
    }

    /** @covers issue #25133 */
    public function testSocketExportImportStreamZendStubNamedParams(): void
    {
        $export = BuiltinParamNames::forFunction('socket_export_stream');
        self::assertSame(['socket'], $export);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($export, 'socket', 'socket_export_stream'));
        self::assertSame(
            ['socket'],
            BuiltinParamNames::paramNamesForInternalFunction('socket_export_stream')
        );

        $import = BuiltinParamNames::forFunction('socket_import_stream');
        self::assertSame(['stream'], $import);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($import, 'stream', 'socket_import_stream'));
    }

    /** @covers issue #24642 */
    public function testSocketStrerrorErrorCodeZendStubNamedParam(): void
    {
        $names = BuiltinParamNames::forFunction('socket_strerror');
        self::assertSame(['error_code'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'error_code', 'socket_strerror'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'errno', 'socket_strerror'));
        self::assertSame(
            ['error_code'],
            BuiltinParamNames::paramNamesForInternalFunction('socket_strerror')
        );
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
        self::assertSame(['pattern', 'subject', 'limit=', 'flags='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'pattern', 'preg_split'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'subject', 'preg_split'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'limit', 'preg_split'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'preg_split'));
    }

    /** @covers issue #10028 #26465 */
    public function testIniGetSetNamedParameters(): void
    {
        $get = BuiltinParamNames::forFunction('ini_get');
        self::assertSame(['option'], $get);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($get, 'option', 'ini_get'));

        foreach (['ini_set', 'ini_alter'] as $fn) {
            $set = BuiltinParamNames::forFunction($fn);
            self::assertSame(['option', 'value'], $set, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($set, 'option', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($set, 'value', $fn), $fn);
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn), $fn);
        }
    }

    /** @covers issue #23569 — Zend stub names vs InternalArgInfo option_name/extension_name/arg */
    public function testGetCfgVarExtensionFuncsCliSetProcessTitleZendStubNamedParams(): void
    {
        $cfg = BuiltinParamNames::forFunction('get_cfg_var');
        self::assertSame(['option'], $cfg);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($cfg, 'option', 'get_cfg_var'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($cfg, 'option_name', 'get_cfg_var'));
        self::assertSame(['option'], BuiltinParamNames::paramNamesForInternalFunction('get_cfg_var'));

        $ext = BuiltinParamNames::forFunction('get_extension_funcs');
        self::assertSame(['extension'], $ext);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ext, 'extension', 'get_extension_funcs'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($ext, 'extension_name', 'get_extension_funcs'));
        self::assertSame(['extension'], BuiltinParamNames::paramNamesForInternalFunction('get_extension_funcs'));

        $title = BuiltinParamNames::forFunction('cli_set_process_title');
        self::assertSame(['title'], $title);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($title, 'title', 'cli_set_process_title'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($title, 'arg', 'cli_set_process_title'));
        self::assertSame(['title'], BuiltinParamNames::paramNamesForInternalFunction('cli_set_process_title'));
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

    /** @covers issue #25580 */
    public function testPregMatchReflectionStubClearsMatchesTypeAndAddsFalseReturn(): void
    {
        foreach (['preg_match', 'preg_match_all'] as $fn) {
            self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 2), $fn);
            self::assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn), $fn);
            $info = BuiltinInternalArgInfo::paramInfoForFunction($fn, 2);
            self::assertNotNull($info, $fn);
            self::assertSame('', $info['type'], $fn);
        }
    }

    /** @covers issue #19697 */
    public function testPregReplaceCallbackArrayNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('preg_replace_callback_array');
        self::assertSame(['pattern', 'subject', 'limit=', 'count=', 'flags='], $names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'limit', 'preg_replace_callback_array'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'count', 'preg_replace_callback_array'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'preg_replace_callback_array'));

        $cb = BuiltinParamNames::forFunction('preg_replace_callback');
        self::assertSame(['pattern', 'callback', 'subject', 'limit=', 'count=', 'flags='], $cb);
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($cb, 'count', 'preg_replace_callback'));
        self::assertSame(5, BuiltinParamNames::lookupNamedParamIndex($cb, 'flags', 'preg_replace_callback'));
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

    /** @covers issue #9647 / #24845 */
    public function testDateNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('date');
        self::assertSame(['format', 'timestamp='], $names);
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
            // #23385 / #26142 — Zend usort arity is always array/callback (no $direction).
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
        self::assertSame(['stream', 'fields', 'separator=', 'enclosure=', 'escape=', 'eol='], $fputcsv);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fputcsv, 'fields', 'fputcsv'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($fputcsv, 'separator', 'fputcsv'));
        self::assertSame(5, BuiltinParamNames::lookupNamedParamIndex($fputcsv, 'eol', 'fputcsv'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('fputcsv'));
        self::assertTrue(BuiltinParamNames::overrideEntryIsOptional('separator='));
        self::assertTrue(BuiltinParamNames::overrideEntryIsOptional('enclosure='));
        self::assertTrue(BuiltinParamNames::overrideEntryIsOptional('escape='));
        self::assertTrue(BuiltinParamNames::overrideEntryIsOptional('eol='));

        $streamGetLine = BuiltinParamNames::forFunction('stream_get_line');
        self::assertSame(['stream', 'length', 'ending='], $streamGetLine);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($streamGetLine, 'length', 'stream_get_line'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($streamGetLine, 'maxlen', 'stream_get_line'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($streamGetLine, 'ending', 'stream_get_line'));

        // php-src basic_functions.stub.php — InternalArgInfo still file_name (#23785)
        $hf = BuiltinParamNames::forFunction('highlight_file');
        self::assertSame(['filename', 'return='], $hf);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($hf, 'filename', 'highlight_file'));
        $ss = BuiltinParamNames::forFunction('show_source');
        self::assertSame(['filename', 'return='], $ss);
        $psw = BuiltinParamNames::forFunction('php_strip_whitespace');
        self::assertSame(['filename'], $psw);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($psw, 'filename', 'php_strip_whitespace'));

        $splFputcsv = BuiltinParamNames::paramNamesForInternalFunction('SplFileObject::fputcsv');
        self::assertSame(['fields', 'separator=', 'enclosure=', 'escape=', 'eol='], $splFputcsv);
        self::assertSame(
            3,
            BuiltinParamNames::lookupNamedParamIndex($splFputcsv, 'escape', 'SplFileObject::fputcsv')
        );
        // Zend stubs use separator; delimiter is Unknown named parameter (#25590)
        self::assertFalse(
            BuiltinParamNames::lookupNamedParamIndex($splFputcsv, 'delimiter', 'SplFileObject::fputcsv')
        );
        self::assertSame(
            1,
            BuiltinParamNames::lookupNamedParamIndex($splFputcsv, 'separator', 'SplFileObject::fputcsv')
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

    /** @covers issue #25590 */
    public function testCsvRejectDelimiterNamedAlias(): void
    {
        $str = BuiltinParamNames::forFunction('str_getcsv');
        self::assertSame(['string', 'separator=', 'enclosure=', 'escape='], $str);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($str, 'separator', 'str_getcsv'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($str, 'delimiter', 'str_getcsv'));
        self::assertSame([], BuiltinParamNames::aliasesForFunction('str_getcsv'));

        $fget = BuiltinParamNames::forFunction('fgetcsv');
        self::assertSame(['stream', 'length', 'separator', 'enclosure', 'escape'], $fget);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($fget, 'separator', 'fgetcsv'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($fget, 'delimiter', 'fgetcsv'));
        self::assertSame([], BuiltinParamNames::aliasesForFunction('fgetcsv'));

        $splGet = BuiltinParamNames::paramNamesForInternalFunction('SplFileObject::fgetcsv');
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($splGet, 'delimiter', 'SplFileObject::fgetcsv'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($splGet, 'separator', 'SplFileObject::fgetcsv'));
    }

    /** @covers issue #23352 */
    public function testFlockWouldBlockNamedParam(): void
    {
        $names = BuiltinParamNames::forFunction('flock');
        self::assertSame(['stream', 'operation', '&would_block='], $names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'would_block', 'flock'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'wouldblock', 'flock'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('flock'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('flock'));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('flock', 2));
        $info = ['name' => 'would_block', 'type' => '', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('flock', 2, $info, false));
        $wb = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($wb, 'flock', 2, $info));
        self::assertSame(Variable::TYPE_NULL, $wb->type);
    }

    /** @covers issue #23459 */
    public function testTempnamDirectoryNamedParam(): void
    {
        $names = BuiltinParamNames::forFunction('tempnam');
        self::assertSame(['directory', 'prefix'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'directory', 'tempnam'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'prefix', 'tempnam'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'dir', 'tempnam'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('tempnam'));
    }

    /** @covers issue #23448 */
    public function testScandirDirectoryNamedParam(): void
    {
        $names = BuiltinParamNames::forFunction('scandir');
        self::assertSame(['directory', 'sorting_order=', 'context='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'directory', 'scandir'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'sorting_order', 'scandir'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'scandir'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'dir', 'scandir'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('scandir'));
        $infoOrder = ['name' => 'sorting_order', 'type' => 'int', 'isOptional' => true];
        $infoCtx = ['name' => 'context', 'type' => '', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('scandir', 1, $infoOrder, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('scandir', 2, $infoCtx, false));
        $order = new Variable();
        $ctx = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($order, 'scandir', 1, $infoOrder));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($ctx, 'scandir', 2, $infoCtx));
        self::assertSame(0, $order->toInt());
        self::assertSame(Variable::TYPE_NULL, $ctx->type);
    }

    /** @covers issue #26320 — InternalArgInfo still says path */
    public function testOpendirDirectoryNamedParam(): void
    {
        $names = BuiltinParamNames::forFunction('opendir');
        self::assertSame(['directory', 'context='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'directory', 'opendir'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'opendir'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'path', 'opendir'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('opendir'));
        self::assertSame(
            ['directory', 'context='],
            BuiltinParamNames::paramNamesForInternalFunction('opendir')
        );
    }

    /** @covers issue #23346 */
    public function testChmodPermissionsNamedParam(): void
    {
        $names = BuiltinParamNames::forFunction('chmod');
        self::assertSame(['filename', 'permissions'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'filename', 'chmod'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'permissions', 'chmod'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'mode', 'chmod'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('chmod'));
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

    /** @covers issue #23259 */
    public function testAbsFloorCeilZendStubNamedParams(): void
    {
        foreach (['abs', 'floor', 'ceil'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['num'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'num', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'number', $fn), $fn);
        }
    }

    /** @covers issue #11785 / #25166 */
    public function testDateTimeClassMethodNamedParameters(): void
    {
        $names = BuiltinParamNames::forClassMethod('DateTime::createFromFormat');
        self::assertSame(['format', 'datetime', 'timezone='], $names);
        self::assertSame(
            1,
            BuiltinParamNames::lookupNamedParamIndex($names, 'datetime', 'DateTime::createFromFormat')
        );
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalMethod('DateTime', 'createFromFormat'));
        self::assertSame(
            ['format', 'datetime', 'timezone='],
            BuiltinParamNames::forClassMethod('DateTimeImmutable::createFromFormat')
        );

        $ctor = BuiltinParamNames::forClassMethod('DateTimeImmutable::__construct');
        self::assertSame(['datetime', 'timezone'], $ctor);
        self::assertSame(
            1,
            BuiltinParamNames::lookupNamedParamIndex($ctor, 'timezone', 'DateTimeImmutable::__construct')
        );
    }

    /** @covers issue #25390 */
    public function testSplAutoloadRegisterReflectionNamedParameters(): void
    {
        $names = BuiltinParamNames::forFunction('spl_autoload_register');
        self::assertSame(['callback=', 'throw=', 'prepend='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', 'spl_autoload_register'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('spl_autoload_register'));
        self::assertSame('?callable', BuiltinInternalArgInfo::stubParamTypeOverride('spl_autoload_register', 0));
        self::assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride('spl_autoload_register', 1));
    }

    /** @covers issue #25392 */
    public function testDateCreateReflectionNamedParametersAndArity(): void
    {
        foreach (['date_create', 'date_create_immutable'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['datetime=', 'timezone='], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'datetime', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'timezone', $fn), $fn);
            self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction($fn), $fn);
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn), $fn);
        }
        self::assertSame('DateTime|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('date_create'));
        self::assertSame(
            'DateTimeImmutable|false',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('date_create_immutable')
        );
    }

    /** @covers issue #25400 */
    public function testDateTimeSetTimeMicrosecondNamedParameters(): void
    {
        foreach (['DateTime::setTime', 'DateTimeImmutable::setTime'] as $qual) {
            $names = BuiltinParamNames::forClassMethod($qual);
            self::assertSame(['hour', 'minute', 'second=', 'microsecond='], $names, $qual);
            self::assertSame(
                3,
                BuiltinParamNames::lookupNamedParamIndex($names, 'microsecond', $qual),
                $qual
            );
        }
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalMethod('DateTime', 'setTime'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalMethod('DateTimeImmutable', 'setTime'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalMethod('DateTime', 'setTime'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalMethod('DateTimeImmutable', 'setTime'));
    }

    /** @covers issue #26098 */
    public function testDateTimeSetMicrosecondNamedParameters(): void
    {
        foreach (['DateTime::setMicrosecond', 'DateTimeImmutable::setMicrosecond'] as $qual) {
            $names = BuiltinParamNames::forClassMethod($qual);
            self::assertSame(['microsecond'], $names, $qual);
            self::assertSame(
                0,
                BuiltinParamNames::lookupNamedParamIndex($names, 'microsecond', $qual),
                $qual
            );
        }
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('DateTime', 'setMicrosecond'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('DateTimeImmutable', 'setMicrosecond'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('DateTime', 'setMicrosecond'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('DateTimeImmutable', 'setMicrosecond'));
    }

    /** @covers issue #26097 */
    public function testDateTimeCreateFromTimestampNamedParameters(): void
    {
        foreach (['DateTime::createFromTimestamp', 'DateTimeImmutable::createFromTimestamp'] as $qual) {
            $names = BuiltinParamNames::forClassMethod($qual);
            self::assertSame(['timestamp'], $names, $qual);
            self::assertSame(
                0,
                BuiltinParamNames::lookupNamedParamIndex($names, 'timestamp', $qual),
                $qual
            );
        }
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('DateTime', 'createFromTimestamp'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('DateTimeImmutable', 'createFromTimestamp'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('DateTime', 'createFromTimestamp'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('DateTimeImmutable', 'createFromTimestamp'));
    }

    /** @covers issue #26223 */
    public function testPdoConnectNamedParameters(): void
    {
        $qual = 'PDO::connect';
        $names = BuiltinParamNames::forClassMethod($qual);
        self::assertSame(['dsn', 'username=', 'password=', 'options='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'dsn', $qual));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'username', $qual));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'password', $qual));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'options', $qual));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('PDO', 'connect'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalMethod('PDO', 'connect'));
        self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', 'connect', 0));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', 'connect', 1));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', 'connect', 2));
        self::assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', 'connect', 3));
    }

    /** @covers issue #24590 */
    public function testPdoConstructPrepareQueryNamedParameters(): void
    {
        $ctor = 'PDO::__construct';
        self::assertSame(
            ['dsn', 'username=', 'password=', 'options='],
            BuiltinParamNames::forClassMethod($ctor)
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod($ctor),
            'dsn',
            $ctor
        ));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod($ctor),
            'password',
            $ctor
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod($ctor),
            'passwd',
            $ctor
        ));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('PDO', '__construct'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalMethod('PDO', '__construct'));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', '__construct', 1));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', '__construct', 2));
        self::assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', '__construct', 3));

        $prepare = 'PDO::prepare';
        self::assertSame(['query', 'options='], BuiltinParamNames::forClassMethod($prepare));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod($prepare),
            'query',
            $prepare
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod($prepare),
            'statement',
            $prepare
        ));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('PDO', 'prepare'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('PDO', 'prepare'));

        $query = 'PDO::query';
        self::assertSame(
            ['query', 'fetchMode=', '...fetchModeArgs'],
            BuiltinParamNames::forClassMethod($query)
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod($query),
            'query',
            $query
        ));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod($query),
            'fetchMode',
            $query
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod($query),
            'sql',
            $query
        ));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('PDO', 'query'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalMethod('PDO', 'query'));
        self::assertSame(2, BuiltinParamNames::variadicParamIndexForFunction($query));
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', 'query', 1));
        self::assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('pdo', 'query', 2));
    }

    /** @covers issue #27599 */
    public function testReflectionPropertyRawValueNamedParameters(): void
    {
        $get = 'ReflectionProperty::getRawValue';
        $set = 'ReflectionProperty::setRawValue';
        self::assertSame(['object'], BuiltinParamNames::forClassMethod($get));
        self::assertSame(['object', 'value'], BuiltinParamNames::forClassMethod($set));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($get), 'object', $get));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($set), 'object', $set));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($set), 'value', $set));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionProperty', 'getRawValue'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('ReflectionProperty', 'getRawValue'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionProperty', 'setRawValue'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('ReflectionProperty', 'setRawValue'));
        self::assertSame('object', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionproperty', 'getrawvalue', 0));
        self::assertSame('object', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionproperty', 'setrawvalue', 0));
        self::assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionproperty', 'setrawvalue', 1));
    }

    /** @covers issue #27741 */
    public function testReflectionClassLazyFactoryNamedParameters(): void
    {
        $ghost = 'ReflectionClass::newLazyGhost';
        $proxy = 'ReflectionClass::newLazyProxy';
        $resetGhost = 'ReflectionClass::resetAsLazyGhost';
        $resetProxy = 'ReflectionClass::resetAsLazyProxy';
        self::assertSame(['initializer', 'options='], BuiltinParamNames::forClassMethod($ghost));
        self::assertSame(['factory', 'options='], BuiltinParamNames::forClassMethod($proxy));
        self::assertSame(['object', 'initializer', 'options='], BuiltinParamNames::forClassMethod($resetGhost));
        self::assertSame(['object', 'factory', 'options='], BuiltinParamNames::forClassMethod($resetProxy));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($ghost), 'initializer', $ghost));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($ghost), 'options', $ghost));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(BuiltinParamNames::forClassMethod($proxy), 'factory', $proxy));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionClass', 'newLazyGhost'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('ReflectionClass', 'newLazyGhost'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionClass', 'newLazyProxy'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('ReflectionClass', 'newLazyProxy'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionClass', 'resetAsLazyGhost'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalMethod('ReflectionClass', 'resetAsLazyGhost'));
        self::assertSame('callable', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionclass', 'newlazyghost', 0));
        self::assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionclass', 'newlazyghost', 1));
        self::assertSame('callable', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionclass', 'newlazyproxy', 0));
        self::assertSame('object', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionclass', 'resetaslazyghost', 0));
        self::assertSame('callable', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionclass', 'resetaslazyghost', 1));
        self::assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionclass', 'resetaslazyghost', 2));
    }

    /** @covers issue #26080 */
    public function testDomCreateFromStringNamedParameters(): void
    {
        $expected = ['source', 'options=', 'overrideEncoding='];
        foreach (['Dom\\HTMLDocument::createFromString', 'Dom\\XMLDocument::createFromString'] as $qual) {
            $names = BuiltinParamNames::forClassMethod($qual);
            self::assertSame($expected, $names, $qual);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'source', $qual), $qual);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'options', $qual), $qual);
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'overrideEncoding', $qual), $qual);
        }
        foreach (['Dom\\HTMLDocument', 'Dom\\XMLDocument'] as $class) {
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod($class, 'createFromString'), $class);
            self::assertSame(3, BuiltinParamNames::paramCountForInternalMethod($class, 'createFromString'), $class);
            self::assertSame(
                'string',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(strtolower($class), 'createfromstring', 0),
                $class
            );
            self::assertSame(
                'int',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(strtolower($class), 'createfromstring', 1),
                $class
            );
            self::assertSame(
                '?string',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(strtolower($class), 'createfromstring', 2),
                $class
            );
        }
    }

    /** @covers issue #27924 */
    public function testDomCreateFromFileNamedParameters(): void
    {
        $expected = ['path', 'options=', 'overrideEncoding='];
        foreach (['Dom\\HTMLDocument::createFromFile', 'Dom\\XMLDocument::createFromFile'] as $qual) {
            $names = BuiltinParamNames::forClassMethod($qual);
            self::assertSame($expected, $names, $qual);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'path', $qual), $qual);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'options', $qual), $qual);
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'overrideEncoding', $qual), $qual);
        }
        foreach (['Dom\\HTMLDocument', 'Dom\\XMLDocument'] as $class) {
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod($class, 'createFromFile'), $class);
            self::assertSame(3, BuiltinParamNames::paramCountForInternalMethod($class, 'createFromFile'), $class);
            self::assertSame(
                'string',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(strtolower($class), 'createfromfile', 0),
                $class
            );
            self::assertSame(
                'int',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(strtolower($class), 'createfromfile', 1),
                $class
            );
            self::assertSame(
                '?string',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(strtolower($class), 'createfromfile', 2),
                $class
            );
        }
    }

    /** @covers issue #28740 */
    public function testDomDocumentInstanceMethodNamedParameters(): void
    {
        self::assertSame(
            ['elementId'],
            BuiltinParamNames::forClassMethod('Dom\\HTMLDocument::getElementById')
        );
        self::assertSame(
            ['elementId'],
            BuiltinParamNames::forClassMethod('Dom\\XMLDocument::getElementById')
        );
        self::assertSame(
            ['elementId'],
            BuiltinParamNames::forClassMethod('Dom\\Document::getElementById')
        );
        self::assertSame(
            ['node='],
            BuiltinParamNames::forClassMethod('Dom\\HTMLDocument::saveHtml')
        );
        $saveXml = ['node=', 'options='];
        foreach (['Dom\\HTMLDocument::saveXml', 'Dom\\XMLDocument::saveXml', 'Dom\\Document::saveXml'] as $qual) {
            self::assertSame($saveXml, BuiltinParamNames::forClassMethod($qual), $qual);
        }
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('Dom\\HTMLDocument', 'getElementById'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('Dom\\HTMLDocument', 'getElementById'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalMethod('Dom\\HTMLDocument', 'saveHtml'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('Dom\\HTMLDocument', 'saveHtml'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalMethod('Dom\\HTMLDocument', 'saveXml'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('Dom\\HTMLDocument', 'saveXml'));
        self::assertSame(
            'string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dom\\htmldocument', 'getelementbyid', 0)
        );
        self::assertSame(
            '?Dom\\Node',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dom\\htmldocument', 'savehtml', 0)
        );
        self::assertSame(
            '?Dom\\Node',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dom\\htmldocument', 'savexml', 0)
        );
        self::assertSame(
            'int',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dom\\htmldocument', 'savexml', 1)
        );
    }

    /** @covers issue #28741 */
    public function testDomHtmlElementSelectorNamedParameters(): void
    {
        $selectors = ['selectors'];
        foreach ([
            'Dom\\Element::querySelector',
            'Dom\\HTMLElement::querySelector',
            'Dom\\HTMLDocument::querySelector',
            'Dom\\Element::querySelectorAll',
            'Dom\\HTMLElement::querySelectorAll',
            'Dom\\Element::closest',
            'Dom\\HTMLElement::closest',
            'Dom\\Element::matches',
            'Dom\\HTMLElement::matches',
        ] as $qual) {
            $names = BuiltinParamNames::forClassMethod($qual);
            self::assertSame($selectors, $names, $qual);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'selectors', $qual), $qual);
        }
        $qualifiedName = ['qualifiedName'];
        foreach ([
            'Dom\\Element::getElementsByTagName',
            'Dom\\HTMLElement::getElementsByTagName',
            'Dom\\HTMLDocument::getElementsByTagName',
        ] as $qual) {
            $names = BuiltinParamNames::forClassMethod($qual);
            self::assertSame($qualifiedName, $names, $qual);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'qualifiedName', $qual), $qual);
        }
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('Dom\\HTMLElement', 'querySelector'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('Dom\\HTMLElement', 'querySelector'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('Dom\\Element', 'closest'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('Dom\\HTMLElement', 'getElementsByTagName'));
        self::assertSame(
            'string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dom\\htmlelement', 'queryselector', 0)
        );
        self::assertSame(
            'string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dom\\element', 'matches', 0)
        );
        self::assertSame(
            'string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dom\\htmlelement', 'getelementsbytagname', 0)
        );
    }

    /** @covers issue #27713 */
    public function testXmlReaderFactoryNamedParameters(): void
    {
        self::assertSame(
            ['source', 'encoding=', 'flags='],
            BuiltinParamNames::forClassMethod('XMLReader::fromString')
        );
        self::assertSame(
            ['uri', 'encoding=', 'flags='],
            BuiltinParamNames::forClassMethod('XMLReader::fromUri')
        );
        $fromStream = BuiltinParamNames::forClassMethod('XMLReader::fromStream');
        self::assertSame(['stream', 'encoding=', 'flags=', 'documentUri='], $fromStream);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($fromStream, 'stream', 'XMLReader::fromStream'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($fromStream, 'documentUri', 'XMLReader::fromStream'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('XMLReader', 'fromString'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalMethod('XMLReader', 'fromString'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalMethod('XMLReader', 'fromStream'));
        self::assertSame(
            'string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('xmlreader', 'fromstring', 0)
        );
        self::assertSame(
            '?string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('xmlreader', 'fromstring', 1)
        );
        self::assertSame(
            'int',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('xmlreader', 'fromstring', 2)
        );
        self::assertSame(
            '?string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('xmlreader', 'fromstream', 3)
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

    /** @covers issue #25172 */
    public function testDateTimeZoneListIdentifiersStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forClassMethod('DateTimeZone::listIdentifiers');
        self::assertSame(['timezoneGroup=', 'countryCode='], $names);
        self::assertSame(
            ['timezoneGroup=', 'countryCode='],
            BuiltinParamNames::paramNamesForInternalFunction('DateTimeZone::listIdentifiers')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'timezoneGroup', 'DateTimeZone::listIdentifiers'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'countryCode', 'DateTimeZone::listIdentifiers'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'what', 'DateTimeZone::listIdentifiers'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'country', 'DateTimeZone::listIdentifiers'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalMethod('DateTimeZone', 'listIdentifiers'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('DateTimeZone', 'listIdentifiers'));
    }

    /** @covers issue #25164 */
    public function testDatePeriodConstructStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forClassMethod('DatePeriod::__construct');
        self::assertSame(['start', 'interval=', 'end=', 'options='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'start', 'DatePeriod::__construct'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'interval', 'DatePeriod::__construct'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'end', 'DatePeriod::__construct'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'DatePeriod::__construct'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'recur', 'DatePeriod::__construct'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('DatePeriod', '__construct'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalMethod('DatePeriod', '__construct'));
    }

    /** @covers issue #27923 */
    public function testDatePeriodCreateFromISO8601StringStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forClassMethod('DatePeriod::createFromISO8601String');
        self::assertSame(['specification', 'options='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'specification', 'DatePeriod::createFromISO8601String'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'DatePeriod::createFromISO8601String'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'isostr', 'DatePeriod::createFromISO8601String'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('DatePeriod', 'createFromISO8601String'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('DatePeriod', 'createFromISO8601String'));
        self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dateperiod', 'createfromiso8601string', 0));
        self::assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('dateperiod', 'createfromiso8601string', 1));
        $info = ['name' => 'options', 'type' => 'int', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('DatePeriod::createFromISO8601String', 1, $info, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'DatePeriod::createFromISO8601String', 1, $info));
        self::assertSame(Variable::TYPE_INTEGER, $dest->type);
        self::assertSame(0, $dest->toInt());
    }

    /** @covers issue #10059 */
    public function testArrayMultisortArraySpliceNamedParameters(): void
    {
        $multisort = BuiltinParamNames::forFunction('array_multisort');
        self::assertSame(['array', 'rest'], $multisort);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($multisort, 'array', 'array_multisort'));
        self::assertSame(1, BuiltinParamNames::variadicParamIndexForFunction('array_multisort'));

        $splice = BuiltinParamNames::forFunction('array_splice');
        self::assertSame(['array', 'offset', 'length=', 'replacement='], $splice);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($splice, 'array', 'array_splice'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($splice, 'offset', 'array_splice'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($splice, 'length', 'array_splice'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($splice, 'replacement', 'array_splice'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('array_splice'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('array_splice'));
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('array_splice', 2));
        self::assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('array_splice', 3));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'array_splice',
            2,
            ['name' => 'length', 'type' => '?int', 'isOptional' => true],
            false
        ));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'array_splice',
            3,
            ['name' => 'replacement', 'type' => 'mixed', 'isOptional' => true],
            false
        ));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'array_splice',
            2,
            ['name' => 'length', 'type' => '?int', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'array_splice',
            3,
            ['name' => 'replacement', 'type' => 'mixed', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_ARRAY, $dest->type);
        self::assertSame(0, $dest->toArray()->getNumElements());
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
        self::assertSame(['array', 'callback=', 'mode='], $filter);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($filter, 'array', 'array_filter'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($filter, 'callback', 'array_filter'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($filter, 'mode', 'array_filter'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('array_filter'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('array_filter'));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'array_filter',
            1,
            ['name' => 'callback', 'type' => '?callable', 'isOptional' => true],
            false
        ));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'array_filter',
            2,
            ['name' => 'mode', 'type' => 'int', 'isOptional' => true],
            false
        ));
        $cbDef = new Variable();
        $modeDef = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $cbDef,
            'array_filter',
            1,
            ['name' => 'callback', 'type' => '?callable', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $cbDef->type);
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $modeDef,
            'array_filter',
            2,
            ['name' => 'mode', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(0, $modeDef->toInt());

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

    /** @covers issue #26143 — bcadd/bcsub/bcmul/bcdiv/bcmod/bcpowmod have no rounding_mode (reverts #9946/#9919) */
    public function testBcmathClassicHasNoRoundingModeNamedParameter(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            foreach (['bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bcmod'] as $fn) {
                $names = BuiltinParamNames::forFunction($fn);
                self::assertSame(['num1', 'num2', 'scale'], $names, $fn);
                self::assertFalse(
                    BuiltinParamNames::lookupNamedParamIndex($names, 'rounding_mode', $fn),
                    $fn
                );
                self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction($fn), $fn);
            }
            $powmod = BuiltinParamNames::forFunction('bcpowmod');
            self::assertSame(['num', 'exponent', 'modulus', 'scale'], $powmod);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($powmod, 'rounding_mode', 'bcpowmod'));
            self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('bcpowmod'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #26145 — bcpow/bcsqrt Zend stub names (InternalArgInfo still x/y/operand) */
    public function testBcpowBcsqrtZendStubNamedParameters(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $pow = BuiltinParamNames::forFunction('bcpow');
            self::assertSame(['num', 'exponent', 'scale'], $pow);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($pow, 'num', 'bcpow'));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($pow, 'exponent', 'bcpow'));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($pow, 'scale', 'bcpow'));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($pow, 'x', 'bcpow'));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($pow, 'y', 'bcpow'));
            self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('bcpow'));

            $sqrt = BuiltinParamNames::forFunction('bcsqrt');
            self::assertSame(['num', 'scale'], $sqrt);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($sqrt, 'num', 'bcsqrt'));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($sqrt, 'scale', 'bcsqrt'));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sqrt, 'operand', 'bcsqrt'));
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('bcsqrt'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #24578 — bcdivmod PHP 8.4 stub names; not in php-types InternalArgInfo */
    public function testBcdivmodReflectionNamedParameters(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $names = BuiltinParamNames::forFunction('bcdivmod');
            self::assertSame(['num1', 'num2', 'scale='], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'num1', 'bcdivmod'));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'num2', 'bcdivmod'));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'scale', 'bcdivmod'));
            self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('bcdivmod'));
            self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('bcdivmod'));
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

    /** @covers issue #23949 (reverts #17320 phantom separator) */
    public function testParseStrNoSeparatorNamedParamOnForwardProfile(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $names = BuiltinParamNames::forFunction('parse_str');
            self::assertSame(['string', 'result'], $names);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'separator', 'parse_str'));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    /** @covers issue #27749 (reverts #17239 phantom substr $truncate) */
    public function testSubstrNoTruncateNamedParamOnForwardProfile(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $names = BuiltinParamNames::forFunction('substr');
            self::assertSame(['string', 'offset', 'length='], $names);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'truncate', 'substr'));
            self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('substr'));
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

    /** @covers issue #17370 / #26258 */
    public function testTokenGetAllFlagsNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('token_get_all');
        self::assertSame(['code', 'flags='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'code', 'token_get_all'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'token_get_all'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('token_get_all'));
        self::assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('token_get_all', 1));
        $info = ['name' => 'flags', 'type' => 'int', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('token_get_all', 1, $info, false));
        $flags = new \PHPCompiler\VM\Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($flags, 'token_get_all', 1, $info));
        self::assertSame(0, $flags->toInt());
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
        self::assertSame(['timestamp='], $getdate);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($getdate, 'timestamp', 'getdate'));

        $gmdate = BuiltinParamNames::forFunction('gmdate');
        self::assertSame(['format', 'timestamp='], $gmdate);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($gmdate, 'format', 'gmdate'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($gmdate, 'timestamp', 'gmdate'));

        $substrCount = BuiltinParamNames::forFunction('substr_count');
        self::assertSame(['haystack', 'needle', 'offset=', 'length='], $substrCount);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($substrCount, 'haystack', 'substr_count'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($substrCount, 'needle', 'substr_count'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($substrCount, 'offset', 'substr_count'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($substrCount, 'length', 'substr_count'));

        $pregQuote = BuiltinParamNames::forFunction('preg_quote');
        self::assertSame(['str', 'delimiter='], $pregQuote);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($pregQuote, 'str', 'preg_quote'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($pregQuote, 'delimiter', 'preg_quote'));
    }

    /** @covers issue #23216 / #24845 */
    public function testStrtotimeZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('strtotime');
        self::assertSame(['datetime', 'baseTimestamp='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'datetime', 'strtotime'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'baseTimestamp', 'strtotime'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $time / $now)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'time', 'strtotime'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'now', 'strtotime'));
    }

    /** @covers issue #26325 */
    public function testStrtotimeMktimeGmmktimeIntFalseReturn(): void
    {
        foreach (['strtotime', 'mktime', 'gmmktime'] as $fn) {
            self::assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn), $fn);
        }
    }

    /** @covers issue #24845 */
    public function testDateGmdateStrtotimeNullableTimestampTypeOverride(): void
    {
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('date', 1));
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('gmdate', 1));
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('strtotime', 1));
        self::assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('date', 0));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('date', 1);
        self::assertNotNull($info);
        self::assertSame('?int', $info['type']);
        self::assertTrue($info['isOptional']);
    }

    /** @covers issue #23276 / #25392 */
    public function testDateCreateZendStubNamedParams(): void
    {
        foreach (['date_create', 'date_create_immutable'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['datetime=', 'timezone='], $names, $fn);
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
        self::assertSame(['url', 'associative=', 'context='], $headers);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($headers, 'url', 'get_headers'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($headers, 'associative', 'get_headers'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($headers, 'context', 'get_headers'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($headers, 'format', 'get_headers'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('get_headers'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('get_headers'));
        self::assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('get_headers'));
        self::assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride('get_headers', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('get_headers', 2));
        self::assertSame('int|bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('http_response_code'));
        self::assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_socket_pair'));
        self::assertSame('void', BuiltinInternalArgInfo::returnTypeLabelForFunction('flush'));
        self::assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('ob_get_status'));
        self::assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('ob_list_handlers'));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('getmxrr', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('getmxrr', 2));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'headers_sent',
            0,
            ['name' => 'filename', 'type' => '', 'isOptional' => true],
            false
        ));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'get_headers',
            2,
            ['name' => 'context', 'type' => '', 'isOptional' => true],
            false
        ));
    }

    /** @covers issue #25046 */
    public function testFilterVarReflectionDefaultsAndTypes(): void
    {
        $names = BuiltinParamNames::forFunction('filter_var');
        self::assertSame(['value', 'filter=', 'options='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'value', 'filter_var'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'filter', 'filter_var'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'filter_var'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'variable', 'filter_var'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('filter_var'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('filter_var'));
        self::assertSame('mixed', BuiltinInternalArgInfo::returnTypeLabelForFunction('filter_var'));
        self::assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('filter_var', 0));
        self::assertSame('array|int', BuiltinInternalArgInfo::stubParamTypeOverride('filter_var', 2));
        $infoFilter = ['name' => 'filter', 'type' => 'int', 'isOptional' => true];
        $infoOptions = ['name' => 'options', 'type' => 'array|int', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('filter_var', 1, $infoFilter, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('filter_var', 2, $infoOptions, false));
        $filter = new Variable();
        $options = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($filter, 'filter_var', 1, $infoFilter));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($options, 'filter_var', 2, $infoOptions));
        self::assertSame(516, $filter->toInt()); // FILTER_DEFAULT
        self::assertSame(0, $options->toInt());
    }

    /** @covers issue #23260 */
    public function testSerializeUnserializeZendStubNamedParamsAndTypes(): void
    {
        $ser = BuiltinParamNames::forFunction('serialize');
        self::assertSame(['value'], $ser);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ser, 'value', 'serialize'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($ser, 'variable', 'serialize'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('serialize'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction('serialize'));
        self::assertSame('mixed', BuiltinInternalArgInfo::stubParamTypeOverride('serialize', 0));

        $uns = BuiltinParamNames::forFunction('unserialize');
        self::assertSame(['data', 'options='], $uns);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($uns, 'data', 'unserialize'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($uns, 'options', 'unserialize'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($uns, 'variable_representation', 'unserialize'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($uns, 'allowed_classes', 'unserialize'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('unserialize'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('unserialize'));
        self::assertSame('mixed', BuiltinInternalArgInfo::returnTypeLabelForFunction('unserialize'));
        self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('unserialize', 0));
        self::assertSame('array', BuiltinInternalArgInfo::stubParamTypeOverride('unserialize', 1));
        $infoOptions = ['name' => 'options', 'type' => 'array', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('unserialize', 1, $infoOptions, false));
        $options = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($options, 'unserialize', 1, $infoOptions));
        self::assertSame(Variable::TYPE_ARRAY, $options->type);
        self::assertSame(0, $options->toArray()->getNumElements());
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

    /** @covers issue #23446 / #25173 */
    public function testTimezoneIdentifiersListZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('timezone_identifiers_list');
        self::assertSame(['timezoneGroup=', 'countryCode='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'timezoneGroup', 'timezone_identifiers_list'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'countryCode', 'timezone_identifiers_list'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $what / $country)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'what', 'timezone_identifiers_list'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'country', 'timezone_identifiers_list'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('timezone_identifiers_list'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('timezone_identifiers_list'));
    }

    /** @covers issue #24359 */
    public function testTimezoneNameFromAbbrZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('timezone_name_from_abbr');
        self::assertSame(['abbr', 'utcOffset=', 'isDST='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'abbr', 'timezone_name_from_abbr'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'utcOffset', 'timezone_name_from_abbr'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'isDST', 'timezone_name_from_abbr'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $gmtoffset)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'gmtoffset', 'timezone_name_from_abbr'));
    }

    /** @covers issue #26358 */
    public function testTimezoneNameFromAbbrReturnAndDefaults(): void
    {
        self::assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('timezone_name_from_abbr'));
        $infoUtc = ['name' => 'utcOffset', 'type' => 'int', 'isOptional' => true];
        $infoIsDst = ['name' => 'isDST', 'type' => 'int', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('timezone_name_from_abbr', 1, $infoUtc, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('timezone_name_from_abbr', 2, $infoIsDst, false));
        $utc = new Variable();
        $isDst = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($utc, 'timezone_name_from_abbr', 1, $infoUtc));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($isDst, 'timezone_name_from_abbr', 2, $infoIsDst));
        self::assertSame(Variable::TYPE_INTEGER, $utc->type);
        self::assertSame(-1, $utc->toInt());
        self::assertSame(Variable::TYPE_INTEGER, $isDst->type);
        self::assertSame(-1, $isDst->toInt());
    }

    /** @covers issue #24360 */
    public function testTimezoneProceduralZendStubNamedParams(): void
    {
        self::assertSame(['object'], BuiltinParamNames::forFunction('timezone_location_get'));
        self::assertSame(['object', 'datetime'], BuiltinParamNames::forFunction('timezone_offset_get'));
        $names = BuiltinParamNames::forFunction('timezone_transitions_get');
        self::assertSame(['object', 'timestampBegin=', 'timestampEnd='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'object', 'timezone_transitions_get'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'timestampBegin', 'timezone_transitions_get'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'timestampEnd', 'timezone_transitions_get'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'timestamp_begin', 'timezone_transitions_get'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'timestamp_end', 'timezone_transitions_get'));
    }

    /** @covers issue #24363 */
    public function testDateSunZendStubNamedParams(): void
    {
        self::assertSame(['timestamp', 'latitude', 'longitude'], BuiltinParamNames::forFunction('date_sun_info'));
        $names = BuiltinParamNames::forFunction('date_sunrise');
        self::assertSame(
            ['timestamp', 'returnFormat=', 'latitude=', 'longitude=', 'zenith=', 'utcOffset='],
            $names
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'timestamp', 'date_sunrise'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'returnFormat', 'date_sunrise'));
        self::assertSame(5, BuiltinParamNames::lookupNamedParamIndex($names, 'utcOffset', 'date_sunrise'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'time', 'date_sunrise'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'format', 'date_sunrise'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'gmt_offset', 'date_sunrise'));
        self::assertSame($names, BuiltinParamNames::forFunction('date_sunset'));
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

    /** @covers issue #23275 / #25147 */
    public function testMktimeGmmktimeZendStubNamedParams(): void
    {
        $expected = ['hour', 'minute=', 'second=', 'month=', 'day=', 'year='];
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
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn), $fn);
            self::assertSame(6, BuiltinParamNames::paramCountForInternalFunction($fn), $fn);
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

    /** @covers issue #23693 */
    public function testHebrevZendStubNamedParams(): void
    {
        foreach (['hebrev', 'hebrevc'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string', 'max_chars_per_line='], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'max_chars_per_line', $fn));
            // Legacy InternalArgInfo name must not resolve (Zend rejects $str)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', $fn));
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
        }
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

    /** @covers issue #24865 */
    public function testStripcslashesZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('stripcslashes');
        self::assertSame(['string'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'stripcslashes'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'stripcslashes'));
    }

    /** @covers issue #28854 */
    public function testMoveUploadedFileZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('move_uploaded_file');
        self::assertSame(['from', 'to'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'from', 'move_uploaded_file'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'to', 'move_uploaded_file'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'path', 'move_uploaded_file'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'new_path', 'move_uploaded_file'));
    }

    /** @covers issue #23264 */
    public function testCryptQuotemetaStrrevStrRot13ZendStubNamedParams(): void
    {
        foreach (['quotemeta', 'strrev', 'str_rot13'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', $fn));
        }

        $crypt = BuiltinParamNames::forFunction('crypt');
        self::assertSame(['string', 'salt'], $crypt);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($crypt, 'string', 'crypt'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($crypt, 'salt', 'crypt'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($crypt, 'str', 'crypt'));
    }

    /** @covers issue #23217 / #25594 */
    public function testStripTagsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('strip_tags');
        self::assertSame(['string', 'allowed_tags'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'strip_tags'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'allowed_tags', 'strip_tags'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $allowable_tags)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'strip_tags'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'allowable_tags', 'strip_tags'));
        self::assertSame('array|string|null', BuiltinInternalArgInfo::stubParamTypeOverride('strip_tags', 1));
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

    /** @covers issue #23242 / #25070 */
    public function testRangeZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('range');
        self::assertSame(['start', 'end', 'step='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'start', 'range'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'end', 'range'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'step', 'range'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('range'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('range'));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'range',
            2,
            ['name' => 'step', 'type' => 'int', 'isOptional' => true],
            false
        ));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'range',
            2,
            ['name' => 'step', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(1, $dest->toInt());
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

    /** @covers issue #23595 / #25469 */
    public function testHashPbkdf2ZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_pbkdf2');
        self::assertSame(
            ['algo', 'password', 'salt', 'iterations', 'length=', 'binary=', 'options='],
            $names
        );
        self::assertSame(5, BuiltinParamNames::lookupNamedParamIndex($names, 'binary', 'hash_pbkdf2'));
        self::assertSame(6, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'hash_pbkdf2'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'raw_output', 'hash_pbkdf2'));
        self::assertSame(4, BuiltinParamNames::requiredParamCountForInternalFunction('hash_pbkdf2'));
        self::assertSame(7, BuiltinParamNames::paramCountForInternalFunction('hash_pbkdf2'));
        self::assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_pbkdf2'));
        self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('hash_pbkdf2', 0));
        self::assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('hash_pbkdf2', 4));
        self::assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride('hash_pbkdf2', 5));
        self::assertSame('array', BuiltinInternalArgInfo::stubParamTypeOverride('hash_pbkdf2', 6));
        $infoLength = ['name' => 'length', 'type' => 'int', 'isOptional' => true];
        $infoBinary = ['name' => 'binary', 'type' => 'bool', 'isOptional' => true];
        $infoOptions = ['name' => 'options', 'type' => 'array', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('hash_pbkdf2', 4, $infoLength, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('hash_pbkdf2', 5, $infoBinary, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('hash_pbkdf2', 6, $infoOptions, false));
        $length = new Variable();
        $binary = new Variable();
        $options = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($length, 'hash_pbkdf2', 4, $infoLength));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($binary, 'hash_pbkdf2', 5, $infoBinary));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($options, 'hash_pbkdf2', 6, $infoOptions));
        self::assertSame(0, $length->toInt());
        self::assertFalse($binary->toBool());
        self::assertSame(Variable::TYPE_ARRAY, $options->type);
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

    /** @covers issue #25068 */
    public function testHashZendStubOptionalDefaults(): void
    {
        $names = BuiltinParamNames::forFunction('hash');
        self::assertSame(['algo', 'data', 'binary=', 'options='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'algo', 'hash'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'data', 'hash'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'binary', 'hash'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'hash'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('hash'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('hash'));
        self::assertSame('array', BuiltinInternalArgInfo::stubParamTypeOverride('hash', 3));
        $infoBinary = ['name' => 'binary', 'type' => 'bool', 'isOptional' => true];
        $infoOptions = ['name' => 'options', 'type' => 'array', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('hash', 2, $infoBinary, false));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('hash', 3, $infoOptions, false));
        $binary = new Variable();
        $options = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($binary, 'hash', 2, $infoBinary));
        self::assertTrue(BuiltinInternalDefaultValues::materialize($options, 'hash', 3, $infoOptions));
        self::assertFalse($binary->toBool());
        self::assertSame(Variable::TYPE_ARRAY, $options->type);
    }

    /** @covers issue #25066 */
    public function testIteratorToArrayZendStubTypeAndPreserveKeys(): void
    {
        $names = BuiltinParamNames::forFunction('iterator_to_array');
        self::assertSame(['iterator', 'preserve_keys='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'iterator', 'iterator_to_array'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'preserve_keys', 'iterator_to_array'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('iterator_to_array'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('iterator_to_array'));
        self::assertSame(
            'Traversable|array',
            BuiltinInternalArgInfo::stubParamTypeOverride('iterator_to_array', 0)
        );
        $info = ['name' => 'preserve_keys', 'type' => 'bool', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('iterator_to_array', 1, $info, false));
        $keys = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($keys, 'iterator_to_array', 1, $info));
        self::assertTrue($keys->toBool());
    }

    /** @covers issue #23586 */
    public function testHashFinalZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_final');
        self::assertSame(['context', 'binary'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'hash_final'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'binary', 'hash_final'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $raw_output)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'raw_output', 'hash_final'));
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
        self::assertSame(['mask='], $umask);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($umask, 'mask', 'umask'));

        $fnmatch = BuiltinParamNames::forFunction('fnmatch');
        self::assertSame(['pattern', 'filename', 'flags'], $fnmatch);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($fnmatch, 'pattern', 'fnmatch'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fnmatch, 'filename', 'fnmatch'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($fnmatch, 'flags', 'fnmatch'));
    }

    /** @covers issue #24885 */
    public function testMkdirReflectionOptionalDefaultsNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('mkdir');
        self::assertSame(['directory', 'permissions=', 'recursive=', 'context='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'directory', 'mkdir'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'permissions', 'mkdir'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'recursive', 'mkdir'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'mkdir'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('mkdir'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('mkdir'));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'mkdir',
            1,
            ['name' => 'permissions', 'type' => 'int', 'isOptional' => true],
            false
        ));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'mkdir',
            3,
            ['name' => 'context', 'type' => '', 'isOptional' => true],
            false
        ));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'mkdir',
            1,
            ['name' => 'permissions', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(0777, $dest->toInt());
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'mkdir',
            3,
            ['name' => 'context', 'type' => '', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
    }

    /** @covers issue #28907 — php-src calendar.stub.php calendar = -1 */
    public function testCalInfoReflectionDefaultMinusOne(): void
    {
        $info = ['name' => 'calendar', 'type' => 'int', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('cal_info', 0, $info, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'cal_info', 0, $info));
        self::assertSame(Variable::TYPE_INTEGER, $dest->type);
        self::assertSame(-1, $dest->toInt());
    }

    /** @covers issue #25171 */
    public function testStrtokReflectionNullTokenDefaultNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('strtok');
        self::assertSame(['string', 'token='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'strtok'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'token', 'strtok'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'strtok'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('strtok'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('strtok'));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('strtok', 1));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('strtok', 1);
        self::assertNotNull($info);
        self::assertSame('?string', $info['type']);
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'strtok',
            1,
            ['name' => 'token', 'type' => '?string', 'isOptional' => true],
            false
        ));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'strtok',
            1,
            ['name' => 'token', 'type' => '?string', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
    }

    /** @covers issue #24855 */
    public function testGetenvReflectionOptionalNameAndLocalOnly(): void
    {
        $names = BuiltinParamNames::forFunction('getenv');
        self::assertSame(['name=', 'local_only='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'name', 'getenv'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'local_only', 'getenv'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'varname', 'getenv'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('getenv'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('getenv'));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('getenv', 0));
        self::assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride('getenv', 1));
        $info0 = BuiltinInternalArgInfo::paramInfoForFunction('getenv', 0);
        self::assertNotNull($info0);
        self::assertSame('?string', $info0['type']);
        $info1 = BuiltinInternalArgInfo::paramInfoForFunction('getenv', 1);
        self::assertNotNull($info1);
        self::assertSame('bool', $info1['type']);
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'getenv',
            0,
            ['name' => 'name', 'type' => '?string', 'isOptional' => true],
            false
        ));
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable(
            'getenv',
            1,
            ['name' => 'local_only', 'type' => 'bool', 'isOptional' => true],
            false
        ));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'getenv',
            0,
            ['name' => 'name', 'type' => '?string', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
        $dest2 = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest2,
            'getenv',
            1,
            ['name' => 'local_only', 'type' => 'bool', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_BOOLEAN, $dest2->type);
        self::assertFalse($dest2->toBool());
    }

    /** @covers issue #23492 */
    public function testGethostbynameZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('gethostbyname');
        self::assertSame(['hostname'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'hostname', 'gethostbyname'));
    }

    /** @covers issue #24788 */
    public function testRadixConvertZendStubNamedParams(): void
    {
        self::assertSame(['binary_string'], BuiltinParamNames::forFunction('bindec'));
        self::assertSame(['hex_string'], BuiltinParamNames::forFunction('hexdec'));
        self::assertSame(['octal_string'], BuiltinParamNames::forFunction('octdec'));
        foreach (['decbin', 'dechex', 'decoct'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['num'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'num', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'decimal_number', $fn));
        }
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('bindec'),
            'binary_string',
            'bindec'
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('bindec'),
            'binary_number',
            'bindec'
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('hexdec'),
            'hexadecimal_number',
            'hexdec'
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('octdec'),
            'octal_number',
            'octdec'
        ));
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

    /** @covers issue #23335 / #24866 */
    public function testStrcmpFamilyZendStubNamedParams(): void
    {
        foreach (['strcmp', 'strcasecmp', 'strnatcmp', 'strnatcasecmp'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string1', 'string2'], $names);
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string1', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'string2', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str1', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str2', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 's1', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 's2', $fn));
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

    /** @covers issue #23262 */
    public function testArrayIsListKeyFirstLastZendStubNamedParams(): void
    {
        foreach (['array_is_list', 'array_key_first', 'array_key_last'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array'], $names);
            self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'input', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'arr', $fn));
        }
    }

    /** @covers issue #25500 */
    public function testArrayChangeKeyCaseZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('array_change_key_case');
        self::assertSame(['array', 'case='], $names);
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('array_change_key_case'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('array_change_key_case'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', 'array_change_key_case'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'case', 'array_change_key_case'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'input', 'array_change_key_case'));
    }

    /** @covers issue #23274 */
    public function testArrayKeysValuesUniqueFlipZendStubNamedParams(): void
    {
        $keys = BuiltinParamNames::forFunction('array_keys');
        self::assertSame(['array', 'filter_value=', 'strict='], $keys);
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('array_keys'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('array_keys'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($keys, 'array', 'array_keys'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($keys, 'filter_value', 'array_keys'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($keys, 'strict', 'array_keys'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($keys, 'input', 'array_keys'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($keys, 'search_value', 'array_keys'));

        foreach (['array_values', 'array_flip'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['array'], $names);
            self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'array', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'input', $fn));
        }

        $unique = BuiltinParamNames::forFunction('array_unique');
        self::assertSame(['array', 'flags='], $unique);
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('array_unique'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('array_unique'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($unique, 'array', 'array_unique'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($unique, 'flags', 'array_unique'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($unique, 'input', 'array_unique'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($unique, 'sort_flags', 'array_unique'));
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
        self::assertSame(['string', 'length=', 'separator='], $chunk);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($chunk, 'string', 'chunk_split'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($chunk, 'length', 'chunk_split'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($chunk, 'separator', 'chunk_split'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $chunklen / $ending)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($chunk, 'str', 'chunk_split'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($chunk, 'chunklen', 'chunk_split'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($chunk, 'ending', 'chunk_split'));

        $split = BuiltinParamNames::forFunction('str_split');
        self::assertSame(['string', 'length='], $split);
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

    /** @covers issue #23292 */
    public function testPasswordGetInfoNeedsRehashZendStubNamedParams(): void
    {
        $info = BuiltinParamNames::forFunction('password_get_info');
        self::assertSame(['hash'], $info);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($info, 'hash', 'password_get_info'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction('password_get_info'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('password_get_info'));

        $rehash = BuiltinParamNames::forFunction('password_needs_rehash');
        self::assertSame(['hash', 'algo', 'options='], $rehash);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($rehash, 'hash', 'password_needs_rehash'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($rehash, 'algo', 'password_needs_rehash'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($rehash, 'options', 'password_needs_rehash'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('password_needs_rehash'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('password_needs_rehash'));
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

    /** @covers issue #23623 */
    public function testMbDetectEncodingZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('mb_detect_encoding');
        self::assertSame(['string', 'encodings=', 'strict='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'mb_detect_encoding'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'encodings', 'mb_detect_encoding'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'strict', 'mb_detect_encoding'));
        // Legacy InternalArgInfo names must not resolve (Zend rejects $str / $encoding_list)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'mb_detect_encoding'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'encoding_list', 'mb_detect_encoding'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('mb_detect_encoding'));
    }

    /** @covers issue #23805 */
    public function testMbStrPadLcfirstUcfirstZendStubNamedParams(): void
    {
        $pad = BuiltinParamNames::forFunction('mb_str_pad');
        self::assertSame(['string', 'length', 'pad_string=', 'pad_type=', 'encoding='], $pad);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($pad, 'string', 'mb_str_pad'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($pad, 'length', 'mb_str_pad'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($pad, 'pad_string', 'mb_str_pad'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($pad, 'pad_type', 'mb_str_pad'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($pad, 'encoding', 'mb_str_pad'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('mb_str_pad'));
        self::assertSame(5, BuiltinParamNames::paramCountForInternalFunction('mb_str_pad'));

        $lc = BuiltinParamNames::forFunction('mb_lcfirst');
        self::assertSame(['string', 'encoding='], $lc);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($lc, 'string', 'mb_lcfirst'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($lc, 'encoding', 'mb_lcfirst'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('mb_lcfirst'));

        $uc = BuiltinParamNames::forFunction('mb_ucfirst');
        self::assertSame(['string', 'encoding='], $uc);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($uc, 'string', 'mb_ucfirst'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($uc, 'encoding', 'mb_ucfirst'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('mb_ucfirst'));
    }

    /** @covers issue #26282 */
    public function testMbUcfirstLcfirstZendStubReflectionTypes(): void
    {
        foreach (['mb_ucfirst', 'mb_lcfirst'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string', 'encoding='], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'encoding', $fn));
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
            self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));
            self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1));
            $infoEncoding = ['name' => 'encoding', 'type' => '?string', 'isOptional' => true];
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 1, $infoEncoding, false));
            $encoding = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($encoding, $fn, 1, $infoEncoding));
            self::assertSame(Variable::TYPE_NULL, $encoding->type);
        }
    }

    /** @covers issue #26283 */
    public function testMbTrimFamilyZendStubReflectionTypes(): void
    {
        foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string', 'characters=', 'encoding='], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'characters', $fn));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'encoding', $fn));
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
            self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction($fn));
            self::assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
            self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));
            self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1));
            self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 2));
            $infoCharacters = ['name' => 'characters', 'type' => '?string', 'isOptional' => true];
            $infoEncoding = ['name' => 'encoding', 'type' => '?string', 'isOptional' => true];
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 1, $infoCharacters, false));
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 2, $infoEncoding, false));
            $characters = new Variable();
            $encoding = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($characters, $fn, 1, $infoCharacters));
            self::assertTrue(BuiltinInternalDefaultValues::materialize($encoding, $fn, 2, $infoEncoding));
            self::assertSame(Variable::TYPE_NULL, $characters->type);
            self::assertSame(Variable::TYPE_NULL, $encoding->type);
        }
    }

    /** @covers issue #23657 */
    public function testMbStrtolowerStrtoupperZendStubNamedParams(): void
    {
        foreach (['mb_strtolower', 'mb_strtoupper'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string', 'encoding='], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'encoding', $fn));
            // Legacy InternalArgInfo name must not resolve (Zend rejects $str)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', $fn));
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
            self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn));
        }
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

    /** @covers issue #26234 */
    public function testFilterHasVarStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('filter_has_var');
        self::assertSame(['input_type', 'var_name'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'input_type', 'filter_has_var'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'var_name', 'filter_has_var'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'type', 'filter_has_var'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'variable_name', 'filter_has_var'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('filter_has_var'));
    }

    /** @covers issue #26235 */
    public function testUtf8EncodeDecodeStubNamedParamsResolve(): void
    {
        foreach (['utf8_encode', 'utf8_decode'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['string'], $names);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'data', $fn));
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
        }
    }

    /** @covers issue #23205 */
    public function testHashEqualsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_equals');
        self::assertSame(['known_string', 'user_string'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'known_string', 'hash_equals'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'user_string', 'hash_equals'));
    }

    /** @covers issue #24490 */
    public function testSodiumCryptoZendStubNamedParams(): void
    {
        $generichash = BuiltinParamNames::forFunction('sodium_crypto_generichash');
        self::assertSame(['message', 'key=', 'length='], $generichash);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($generichash, 'message', 'sodium_crypto_generichash'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($generichash, 'key', 'sodium_crypto_generichash'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($generichash, 'length', 'sodium_crypto_generichash'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('sodium_crypto_generichash'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('sodium_crypto_generichash'));

        $secretbox = BuiltinParamNames::forFunction('sodium_crypto_secretbox');
        self::assertSame(['message', 'nonce', 'key'], $secretbox);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($secretbox, 'message', 'sodium_crypto_secretbox'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($secretbox, 'nonce', 'sodium_crypto_secretbox'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($secretbox, 'key', 'sodium_crypto_secretbox'));

        $box = BuiltinParamNames::forFunction('sodium_crypto_box');
        self::assertSame(['message', 'nonce', 'key_pair'], $box);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($box, 'key_pair', 'sodium_crypto_box'));

        $sign = BuiltinParamNames::forFunction('sodium_crypto_sign');
        self::assertSame(['message', 'secret_key'], $sign);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($sign, 'secret_key', 'sodium_crypto_sign'));

        $pwhash = BuiltinParamNames::forFunction('sodium_crypto_pwhash_str');
        self::assertSame(['password', 'opslimit', 'memlimit'], $pwhash);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($pwhash, 'password', 'sodium_crypto_pwhash_str'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($pwhash, 'opslimit', 'sodium_crypto_pwhash_str'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($pwhash, 'memlimit', 'sodium_crypto_pwhash_str'));
        self::assertSame(3, BuiltinParamNames::requiredParamCountForInternalFunction('sodium_crypto_pwhash_str'));
    }

    /** @covers issue #23290 / #25018 */
    public function testHashHkdfZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('hash_hkdf');
        self::assertSame(['algo', 'key', 'length=', 'info=', 'salt='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'algo', 'hash_hkdf'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'key', 'hash_hkdf'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'length', 'hash_hkdf'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'info', 'hash_hkdf'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'salt', 'hash_hkdf'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('hash_hkdf'));
        self::assertSame(5, BuiltinParamNames::paramCountForInternalFunction('hash_hkdf'));
    }

    /** @covers issue #23307, #24364, #24567 */
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
        // php-src iconv.stub.php — ?string $encoding = null → int|false (#27629)
        self::assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('iconv_strlen'));
        $enc = BuiltinInternalArgInfo::paramInfoForFunction('iconv_strlen', 1);
        self::assertNotNull($enc);
        self::assertSame('?string', $enc['type']);
        self::assertTrue($enc['isOptional']);
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('iconv_strlen', 1));

        $substr = BuiltinParamNames::forFunction('iconv_substr');
        self::assertSame(['string', 'offset', 'length', 'encoding'], $substr);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($substr, 'string', 'iconv_substr'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($substr, 'offset', 'iconv_substr'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($substr, 'length', 'iconv_substr'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($substr, 'encoding', 'iconv_substr'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($substr, 'str', 'iconv_substr'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($substr, 'charset', 'iconv_substr'));

        // php-src iconv.stub.php — encoding not charset (#24364)
        $strpos = BuiltinParamNames::forFunction('iconv_strpos');
        self::assertSame(['haystack', 'needle', 'offset', 'encoding'], $strpos);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($strpos, 'haystack', 'iconv_strpos'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($strpos, 'needle', 'iconv_strpos'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($strpos, 'offset', 'iconv_strpos'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($strpos, 'encoding', 'iconv_strpos'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($strpos, 'charset', 'iconv_strpos'));

        $strrpos = BuiltinParamNames::forFunction('iconv_strrpos');
        self::assertSame(['haystack', 'needle', 'encoding'], $strrpos);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($strrpos, 'haystack', 'iconv_strrpos'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($strrpos, 'needle', 'iconv_strrpos'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($strrpos, 'encoding', 'iconv_strrpos'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($strrpos, 'charset', 'iconv_strrpos'));

        // php-src iconv.stub.php — options not preference (#24567)
        $mime = BuiltinParamNames::forFunction('iconv_mime_encode');
        self::assertSame(['field_name', 'field_value', 'options='], $mime);
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('iconv_mime_encode'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('iconv_mime_encode'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($mime, 'field_name', 'iconv_mime_encode'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($mime, 'field_value', 'iconv_mime_encode'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($mime, 'options', 'iconv_mime_encode'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($mime, 'preference', 'iconv_mime_encode'));
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
            self::assertSame(['value', 'mode='], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'value', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'mode', $fn), $fn);
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'var', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn), $fn);
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
        foreach (['fclose', 'feof', 'fgetc', 'ftell', 'rewind', 'fflush', 'fsync', 'fdatasync'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['stream'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'stream', $fn), $fn);
            // Legacy InternalArgInfo name must not resolve (Zend rejects $fp)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'fp', $fn), $fn);
        }
        self::assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('fsync'));
        self::assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('fdatasync'));

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

    /** @covers issue #25016 */
    public function testGetMangledObjectVarsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('get_mangled_object_vars');
        self::assertSame(['object'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'object', 'get_mangled_object_vars'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalFunction('get_mangled_object_vars'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('get_mangled_object_vars'));
        self::assertSame(
            'object',
            BuiltinInternalArgInfo::stubParamTypeOverride('get_mangled_object_vars', 0)
        );
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

    /** @covers issue #23568 */
    public function testIgnoreUserAbortIncludePathIniRestoreZendStubNamedParams(): void
    {
        $abort = BuiltinParamNames::forFunction('ignore_user_abort');
        self::assertSame(['enable='], $abort);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($abort, 'enable', 'ignore_user_abort'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('ignore_user_abort'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $value)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($abort, 'value', 'ignore_user_abort'));

        $path = BuiltinParamNames::forFunction('set_include_path');
        self::assertSame(['include_path'], $path);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($path, 'include_path', 'set_include_path'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($path, 'new_include_path', 'set_include_path'));

        $restore = BuiltinParamNames::forFunction('ini_restore');
        self::assertSame(['option'], $restore);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($restore, 'option', 'ini_restore'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($restore, 'varname', 'ini_restore'));
    }

    /** @covers issue #24583 */
    public function testSessionCacheLimiterExpireZendStubNamedParams(): void
    {
        $limiter = BuiltinParamNames::forFunction('session_cache_limiter');
        self::assertSame(['value='], $limiter);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($limiter, 'value', 'session_cache_limiter'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($limiter, 'new_cache_limiter', 'session_cache_limiter'));

        $expire = BuiltinParamNames::forFunction('session_cache_expire');
        self::assertSame(['value='], $expire);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($expire, 'value', 'session_cache_expire'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($expire, 'new_cache_expire', 'session_cache_expire'));
    }

    /** @covers issue #23846 / #24533 */
    public function testSessionSetCookieParamsZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('session_set_cookie_params');
        self::assertSame(['lifetime_or_options', 'path=', 'domain=', 'secure=', 'httponly='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'lifetime_or_options', 'session_set_cookie_params'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'lifetime', 'session_set_cookie_params'));
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
        // Absent from InternalArgInfo — `=` encodes optional error_level (#25174)
        self::assertSame(['message', 'error_level='], $ue);
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('user_error'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($ue, 'error_level', 'user_error'));

        $sid = BuiltinParamNames::forFunction('session_id');
        self::assertSame(['id='], $sid);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($sid, 'id', 'session_id'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sid, 'newid', 'session_id'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('session_id'));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('session_id', 0));
        self::assertSame('?string', BuiltinInternalArgInfo::paramInfoForFunction('session_id', 0)['type']);
        self::assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('session_id'));
        $infoId = ['name' => 'id', 'type' => '?string', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('session_id', 0, $infoId, false));
        $idDefault = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($idDefault, 'session_id', 0, $infoId));
        self::assertSame(Variable::TYPE_NULL, $idDefault->type);
    }

    /** @covers issue #25587 */
    public function testIntlErrorNameAndResourcebundleCreateZendStubReflection(): void
    {
        $ie = BuiltinParamNames::forFunction('intl_error_name');
        self::assertSame(['errorCode'], $ie);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ie, 'errorCode', 'intl_error_name'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($ie, 'error_code', 'intl_error_name'));
        self::assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction('intl_error_name'));
        self::assertSame('int', BuiltinInternalArgInfo::paramInfoForFunction('intl_error_name', 0)['type']);

        $rb = BuiltinParamNames::forFunction('resourcebundle_create');
        self::assertSame(['locale', 'bundle', 'fallback='], $rb);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($rb, 'locale', 'resourcebundle_create'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($rb, 'bundle', 'resourcebundle_create'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($rb, 'fallback', 'resourcebundle_create'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($rb, 'bundlename', 'resourcebundle_create'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('resourcebundle_create'));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('resourcebundle_create', 0));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('resourcebundle_create', 1));
        self::assertSame('?string', BuiltinInternalArgInfo::paramInfoForFunction('resourcebundle_create', 0)['type']);
        self::assertSame('?string', BuiltinInternalArgInfo::paramInfoForFunction('resourcebundle_create', 1)['type']);
        self::assertSame('?ResourceBundle', BuiltinInternalArgInfo::returnTypeLabelForFunction('resourcebundle_create'));
        $infoFallback = ['name' => 'fallback', 'type' => 'bool', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('resourcebundle_create', 2, $infoFallback, false));
        $fallbackDefault = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($fallbackDefault, 'resourcebundle_create', 2, $infoFallback));
        self::assertSame(Variable::TYPE_BOOLEAN, $fallbackDefault->type);
        self::assertTrue($fallbackDefault->toBool());
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

    /** @covers issue #23422 / #25388 */
    public function testClassAliasZendStubNamedParams(): void
    {
        $names = BuiltinParamNames::forFunction('class_alias');
        self::assertSame(['class', 'alias', 'autoload='], $names);
        self::assertTrue(BuiltinParamNames::namesEncodeOptionalParams($names));
        self::assertFalse(BuiltinParamNames::overrideEntryIsOptional('class'));
        self::assertFalse(BuiltinParamNames::overrideEntryIsOptional('alias'));
        self::assertTrue(BuiltinParamNames::overrideEntryIsOptional('autoload='));
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

    /** @covers issue #27922 */
    public function testXmlWriterFactoryNamedParameters(): void
    {
        self::assertSame([], BuiltinParamNames::forClassMethod('XMLWriter::toMemory'));
        self::assertSame(0, BuiltinParamNames::paramCountForInternalMethod('XMLWriter', 'toMemory'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalMethod('XMLWriter', 'toMemory'));

        $toUri = BuiltinParamNames::forClassMethod('XMLWriter::toUri');
        self::assertSame(['uri'], $toUri);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($toUri, 'uri', 'XMLWriter::toUri'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('XMLWriter', 'toUri'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('XMLWriter', 'toUri'));
        self::assertSame(
            'string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('xmlwriter', 'touri', 0)
        );

        $toStream = BuiltinParamNames::forClassMethod('XMLWriter::toStream');
        self::assertSame(['stream'], $toStream);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($toStream, 'stream', 'XMLWriter::toStream'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('XMLWriter', 'toStream'));
        self::assertNull(
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('xmlwriter', 'tostream', 0)
        );
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

    /** @covers issue #26181 */
    public function testFinfoConstructStubNamedParamsResolve(): void
    {
        $ctor = BuiltinParamNames::forClassMethod('finfo::__construct');
        self::assertSame(['flags=', 'magic_database='], $ctor);
        self::assertSame(
            ['flags=', 'magic_database='],
            BuiltinParamNames::paramNamesForInternalFunction('finfo::__construct')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ctor, 'flags', 'finfo::__construct'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($ctor, 'magic_database', 'finfo::__construct'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($ctor, 'options', 'finfo::__construct'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($ctor, 'magic_file', 'finfo::__construct'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalMethod('finfo', '__construct'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalMethod('finfo', '__construct'));
        self::assertSame(
            '?string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('finfo', '__construct', 1)
        );
    }

    /** @covers issue #24626 */
    public function testBcMathNumberConstructStubNamedParamsResolve(): void
    {
        $ctor = BuiltinParamNames::forClassMethod('bcmath\\number::__construct');
        self::assertSame(['num'], $ctor);
        self::assertSame(
            ['num'],
            BuiltinParamNames::paramNamesForInternalFunction('BcMath\\Number::__construct')
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ctor, 'num', 'BcMath\\Number::__construct'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($ctor, 'value', 'BcMath\\Number::__construct'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('BcMath\\Number', '__construct'));
        self::assertSame(1, BuiltinParamNames::paramCountForInternalMethod('BcMath\\Number', '__construct'));
        self::assertSame(
            'string|int',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('bcmath\\number', '__construct', 0)
        );
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

    /** @covers issue #25510 */
    public function testSimpleXmlLoadReflectionStubTypesAndDefaults(): void
    {
        foreach (['simplexml_load_string', 'simplexml_load_file'] as $fn) {
            self::assertSame(
                'SimpleXMLElement|false',
                BuiltinInternalArgInfo::stubReturnTypeLabelForFunction($fn)
            );
            self::assertSame(
                'SimpleXMLElement|false',
                BuiltinInternalArgInfo::returnTypeLabelForFunction($fn)
            );
            self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1));
            self::assertNull(BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));

            $classInfo = BuiltinInternalArgInfo::paramInfoForFunction($fn, 1);
            self::assertNotNull($classInfo);
            self::assertSame('?string', $classInfo['type']);
            self::assertTrue($classInfo['isOptional']);
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 1, $classInfo, false));
            $classDef = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($classDef, $fn, 1, $classInfo));
            self::assertSame(Variable::TYPE_STRING, $classDef->type);
            self::assertSame('SimpleXMLElement', $classDef->toString());

            $nsInfo = BuiltinInternalArgInfo::paramInfoForFunction($fn, 3);
            self::assertNotNull($nsInfo);
            self::assertSame('string', $nsInfo['type']);
            self::assertTrue($nsInfo['isOptional']);
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 3, $nsInfo, false));
            $nsDef = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($nsDef, $fn, 3, $nsInfo));
            self::assertSame(Variable::TYPE_STRING, $nsDef->type);
            self::assertSame('', $nsDef->toString());
        }
    }

    /** @covers issue #26464 */
    public function testDomSimpleXmlBridgeReflectionStubTypesAndDefaults(): void
    {
        self::assertSame(
            'DOMAttr|DOMElement',
            BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('dom_import_simplexml')
        );
        self::assertSame(
            'DOMAttr|DOMElement',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('dom_import_simplexml')
        );
        self::assertSame('object', BuiltinInternalArgInfo::stubParamTypeOverride('dom_import_simplexml', 0));
        $domNode = BuiltinInternalArgInfo::paramInfoForFunction('dom_import_simplexml', 0);
        self::assertNotNull($domNode);
        self::assertSame('object', $domNode['type']);
        self::assertFalse($domNode['isOptional']);

        self::assertSame(
            '?SimpleXMLElement',
            BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('simplexml_import_dom')
        );
        self::assertSame(
            '?SimpleXMLElement',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('simplexml_import_dom')
        );
        self::assertSame(
            'SimpleXMLElement|DOMNode',
            BuiltinInternalArgInfo::stubParamTypeOverride('simplexml_import_dom', 0)
        );
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('simplexml_import_dom', 1));

        $nodeInfo = BuiltinInternalArgInfo::paramInfoForFunction('simplexml_import_dom', 0);
        self::assertNotNull($nodeInfo);
        self::assertSame('SimpleXMLElement|DOMNode', $nodeInfo['type']);
        self::assertFalse($nodeInfo['isOptional']);

        $classInfo = BuiltinInternalArgInfo::paramInfoForFunction('simplexml_import_dom', 1);
        self::assertNotNull($classInfo);
        self::assertSame('?string', $classInfo['type']);
        self::assertTrue($classInfo['isOptional']);
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('simplexml_import_dom', 1, $classInfo, false));
        $classDef = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($classDef, 'simplexml_import_dom', 1, $classInfo));
        self::assertSame(Variable::TYPE_STRING, $classDef->type);
        self::assertSame('SimpleXMLElement', $classDef->toString());
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

    /** @covers issue #26589 */
    public function testXmlSetHandlerStubNamedParamsResolve(): void
    {
        $funcs = [
            'xml_set_character_data_handler',
            'xml_set_default_handler',
            'xml_set_end_namespace_decl_handler',
            'xml_set_external_entity_ref_handler',
            'xml_set_notation_decl_handler',
            'xml_set_processing_instruction_handler',
            'xml_set_start_namespace_decl_handler',
            'xml_set_unparsed_entity_decl_handler',
        ];
        foreach ($funcs as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['parser', 'handler'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'parser', $fn), $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'handler', $fn), $fn);
            // Legacy InternalArgInfo name must not resolve (Zend rejects $hdl)
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'hdl', $fn), $fn);
        }
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

    /** @covers issue #26236 */
    public function testLibxmlSetStreamsContextStubNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('libxml_set_streams_context');
        self::assertSame(['context'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'context', 'libxml_set_streams_context'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects $streams_context)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'streams_context', 'libxml_set_streams_context'));
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
        self::assertSame(['context', 'wrapper_or_options', 'option_name=', 'value='], $opt);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($opt, 'wrapper_or_options', 'stream_context_set_option'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($opt, 'option_name', 'stream_context_set_option'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($opt, 'wrappername', 'stream_context_set_option'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($opt, 'optionname', 'stream_context_set_option'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('stream_context_set_option'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('stream_context_set_option'));

        $setOptions = BuiltinParamNames::forFunction('stream_context_set_options');
        self::assertSame(['context', 'options'], $setOptions);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($setOptions, 'context', 'stream_context_set_options'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($setOptions, 'options', 'stream_context_set_options'));

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

    /** @covers issue #24639 */
    public function testFtpResidualNamedParamsMatchZendStub(): void
    {
        $expected = [
            'ftp_pwd' => ['ftp'],
            'ftp_cdup' => ['ftp'],
            'ftp_systype' => ['ftp'],
            'ftp_nb_continue' => ['ftp'],
            'ftp_close' => ['ftp'],
            'ftp_quit' => ['ftp'],
            'ftp_chdir' => ['ftp', 'directory'],
            'ftp_mkdir' => ['ftp', 'directory'],
            'ftp_rmdir' => ['ftp', 'directory'],
            'ftp_nlist' => ['ftp', 'directory'],
            'ftp_mlsd' => ['ftp', 'directory'],
            'ftp_rawlist' => ['ftp', 'directory', 'recursive'],
            'ftp_exec' => ['ftp', 'command'],
            'ftp_raw' => ['ftp', 'command'],
            'ftp_site' => ['ftp', 'command'],
            'ftp_chmod' => ['ftp', 'permissions', 'filename'],
            'ftp_alloc' => ['ftp', 'size', '&response='],
            'ftp_pasv' => ['ftp', 'enable'],
            'ftp_size' => ['ftp', 'filename'],
            'ftp_mdtm' => ['ftp', 'filename'],
            'ftp_delete' => ['ftp', 'filename'],
            'ftp_rename' => ['ftp', 'from', 'to'],
            'ftp_get_option' => ['ftp', 'option'],
            'ftp_set_option' => ['ftp', 'option', 'value'],
            'ftp_nb_get' => ['ftp', 'local_filename', 'remote_filename', 'mode', 'offset'],
            'ftp_nb_put' => ['ftp', 'remote_filename', 'local_filename', 'mode', 'offset'],
            'ftp_append' => ['ftp', 'remote_filename', 'local_filename', 'mode'],
            'ftp_fget' => ['ftp', 'stream', 'remote_filename', 'mode', 'offset'],
            'ftp_nb_fget' => ['ftp', 'stream', 'remote_filename', 'mode', 'offset'],
            'ftp_fput' => ['ftp', 'remote_filename', 'stream', 'mode', 'offset'],
            'ftp_nb_fput' => ['ftp', 'remote_filename', 'stream', 'mode', 'offset'],
        ];

        foreach ($expected as $fn => $names) {
            self::assertSame($names, BuiltinParamNames::forFunction($fn), $fn);
            self::assertSame(
                0,
                BuiltinParamNames::lookupNamedParamIndex($names, 'ftp', $fn),
                $fn . ' accepts ftp:'
            );
            // Connection must not keep the pre-stub InternalArgInfo name.
            self::assertNotSame('stream', $names[0] ?? null, $fn);
        }

        $chmod = BuiltinParamNames::forFunction('ftp_chmod');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($chmod, 'permissions', 'ftp_chmod'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($chmod, 'mode', 'ftp_chmod'));

        $rename = BuiltinParamNames::forFunction('ftp_rename');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($rename, 'from', 'ftp_rename'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($rename, 'to', 'ftp_rename'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($rename, 'src', 'ftp_rename'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($rename, 'dest', 'ftp_rename'));

        $pasv = BuiltinParamNames::forFunction('ftp_pasv');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($pasv, 'enable', 'ftp_pasv'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($pasv, 'pasv', 'ftp_pasv'));

        $delete = BuiltinParamNames::forFunction('ftp_delete');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($delete, 'filename', 'ftp_delete'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($delete, 'file', 'ftp_delete'));

        $site = BuiltinParamNames::forFunction('ftp_site');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($site, 'command', 'ftp_site'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($site, 'cmd', 'ftp_site'));

        $fget = BuiltinParamNames::forFunction('ftp_fget');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($fget, 'stream', 'ftp_fget'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($fget, 'remote_filename', 'ftp_fget'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($fget, 'offset', 'ftp_fget'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($fget, 'fp', 'ftp_fget'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($fget, 'remote_file', 'ftp_fget'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($fget, 'resumepos', 'ftp_fget'));

        $nbGet = BuiltinParamNames::forFunction('ftp_nb_get');
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($nbGet, 'local_filename', 'ftp_nb_get'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($nbGet, 'local_file', 'ftp_nb_get'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($nbGet, 'resume_pos', 'ftp_nb_get'));
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

    /** @covers issue #27685 */
    public function testOpensslPkeyDeriveNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('openssl_pkey_derive');
        self::assertSame(['public_key', 'private_key', 'key_length='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'public_key', 'openssl_pkey_derive'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'private_key', 'openssl_pkey_derive'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'key_length', 'openssl_pkey_derive'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'peer_key', 'openssl_pkey_derive'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('openssl_pkey_derive'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('openssl_pkey_derive'));
        self::assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('openssl_pkey_derive'));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_pkey_derive', 0));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_pkey_derive', 1));
        self::assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_pkey_derive', 2));
    }

    /** @covers issue #28754 */
    public function testOpensslSealOpenNamedParamsResolve(): void
    {
        $seal = BuiltinParamNames::forFunction('openssl_seal');
        self::assertSame(
            ['data', 'sealed_data', 'encrypted_keys', 'public_key', 'cipher_algo', 'iv='],
            $seal
        );
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($seal, 'sealed_data', 'openssl_seal'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($seal, 'encrypted_keys', 'openssl_seal'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($seal, 'public_key', 'openssl_seal'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($seal, 'cipher_algo', 'openssl_seal'));
        self::assertSame(5, BuiltinParamNames::lookupNamedParamIndex($seal, 'iv', 'openssl_seal'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($seal, 'sealdata', 'openssl_seal'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($seal, 'ekeys', 'openssl_seal'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($seal, 'pubkeys', 'openssl_seal'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($seal, 'method', 'openssl_seal'));
        self::assertSame(5, BuiltinParamNames::requiredParamCountForInternalFunction('openssl_seal'));
        self::assertSame(6, BuiltinParamNames::paramCountForInternalFunction('openssl_seal'));
        self::assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('openssl_seal'));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_seal', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_seal', 2));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_seal', 5));

        $open = BuiltinParamNames::forFunction('openssl_open');
        self::assertSame(
            ['data', 'output', 'encrypted_key', 'private_key', 'cipher_algo', 'iv='],
            $open
        );
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($open, 'output', 'openssl_open'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($open, 'encrypted_key', 'openssl_open'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($open, 'private_key', 'openssl_open'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($open, 'opendata', 'openssl_open'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($open, 'ekey', 'openssl_open'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($open, 'privkey', 'openssl_open'));
        self::assertSame(5, BuiltinParamNames::requiredParamCountForInternalFunction('openssl_open'));
        self::assertSame(6, BuiltinParamNames::paramCountForInternalFunction('openssl_open'));
        self::assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('openssl_open'));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_open', 1));
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('openssl_open', 5));
    }

    /** @covers issue #27884 */
    public function testGraphemeNamedParamsResolve(): void
    {
        $strlen = BuiltinParamNames::forFunction('grapheme_strlen');
        self::assertSame(['string'], $strlen);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($strlen, 'string', 'grapheme_strlen'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($strlen, 'str', 'grapheme_strlen'));
        self::assertSame('int|false|null', BuiltinInternalArgInfo::returnTypeLabelForFunction('grapheme_strlen'));
        self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('grapheme_strlen', 0));

        $substr = BuiltinParamNames::forFunction('grapheme_substr');
        self::assertSame(['string', 'offset', 'length='], $substr);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($substr, 'offset', 'grapheme_substr'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($substr, 'start', 'grapheme_substr'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('grapheme_substr'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('grapheme_substr'));
        self::assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('grapheme_substr'));
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('grapheme_substr', 2));

        $strstr = BuiltinParamNames::forFunction('grapheme_strstr');
        self::assertSame(['haystack', 'needle', 'beforeNeedle='], $strstr);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($strstr, 'beforeNeedle', 'grapheme_strstr'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($strstr, 'part', 'grapheme_strstr'));
        self::assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('grapheme_strstr'));

        $extract = BuiltinParamNames::forFunction('grapheme_extract');
        self::assertSame(['haystack', 'size', 'type=', 'offset=', '&next='], $extract);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($extract, 'haystack', 'grapheme_extract'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($extract, 'type', 'grapheme_extract'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($extract, 'offset', 'grapheme_extract'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($extract, 'next', 'grapheme_extract'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($extract, 'str', 'grapheme_extract'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($extract, 'extract_type', 'grapheme_extract'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($extract, 'start', 'grapheme_extract'));
        self::assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('grapheme_extract'));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('grapheme_extract', 4));

        self::assertSame(
            ['haystack', 'needle', 'beforeNeedle='],
            BuiltinParamNames::forFunction('grapheme_stristr')
        );
        self::assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('grapheme_strpos'));
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

    /** @covers issue #27849 — php-src ext/pcntl/pcntl.stub.php */
    public function testPcntlWaitpidNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('pcntl_waitpid');
        self::assertSame(['process_id', '&status', 'flags=', '&resource_usage='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'process_id', 'pcntl_waitpid'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'status', 'pcntl_waitpid'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'pcntl_waitpid'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'resource_usage', 'pcntl_waitpid'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'pid', 'pcntl_waitpid'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'options', 'pcntl_waitpid'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'rusage', 'pcntl_waitpid'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('pcntl_waitpid'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('pcntl_waitpid'));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('pcntl_waitpid', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('pcntl_waitpid', 3));
        self::assertSame(
            ['process_id', '&status', 'flags=', '&resource_usage='],
            BuiltinParamNames::paramNamesForInternalFunction('pcntl_waitpid')
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

    /** @covers issue #27942 — ext/hash/hash.stub.php hash_hmac_algos(): array */
    public function testHashHmacAlgosStubReturnArray(): void
    {
        self::assertSame('array', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('hash_hmac_algos'));
        self::assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_hmac_algos'));
        self::assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_algos'));
    }

    /** @covers issue #23786 */
    public function testHtmlHashObNamedParamsResolve(): void
    {
        $html = BuiltinParamNames::forFunction('get_html_translation_table');
        self::assertSame(['table=', 'flags=', 'encoding='], $html);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($html, 'table', 'get_html_translation_table'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('get_html_translation_table'));

        $update = BuiltinParamNames::forFunction('hash_update');
        self::assertSame(['context', 'data'], $update);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($update, 'context', 'hash_update'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($update, 'data', 'hash_update'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('hash_update'));

        $stream = BuiltinParamNames::forFunction('hash_update_stream');
        self::assertSame(['context', 'stream', 'length='], $stream);
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($stream, 'stream', 'hash_update_stream'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($stream, 'handle', 'hash_update_stream'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('hash_update_stream'));
        self::assertSame(
            ['context', 'stream', 'length='],
            BuiltinParamNames::paramNamesForInternalFunction('hash_update_stream')
        );

        $ob = BuiltinParamNames::forFunction('ob_get_status');
        self::assertSame(['full_status='], $ob);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($ob, 'full_status', 'ob_get_status'));
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('ob_get_status'));
    }

    /** @covers issue #24455 */
    public function testObImplicitFlushZendStubNamedEnable(): void
    {
        $names = BuiltinParamNames::forFunction('ob_implicit_flush');
        self::assertSame(['enable='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'enable', 'ob_implicit_flush'));
        // Legacy InternalArgInfo name must not resolve (Zend rejects flag)
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'flag', 'ob_implicit_flush'));
        self::assertSame(
            ['enable='],
            BuiltinParamNames::paramNamesForInternalFunction('ob_implicit_flush')
        );
        self::assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('ob_implicit_flush'));
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

    /** @covers issue #24663 */
    public function testOpensslX509ParseCsrNewPkcs7SignNamedParamsResolve(): void
    {
        $parse = BuiltinParamNames::forFunction('openssl_x509_parse');
        self::assertSame(['certificate', 'short_names='], $parse);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($parse, 'certificate', 'openssl_x509_parse'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($parse, 'short_names', 'openssl_x509_parse'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($parse, 'x509', 'openssl_x509_parse'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($parse, 'shortnames', 'openssl_x509_parse'));
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('openssl_x509_parse'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('openssl_x509_parse'));
        self::assertSame(
            ['certificate', 'short_names='],
            BuiltinParamNames::paramNamesForInternalFunction('openssl_x509_parse')
        );

        $csr = BuiltinParamNames::forFunction('openssl_csr_new');
        self::assertSame(['distinguished_names', 'private_key', 'options=', 'extra_attributes='], $csr);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($csr, 'distinguished_names', 'openssl_csr_new'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($csr, 'private_key', 'openssl_csr_new'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($csr, 'options', 'openssl_csr_new'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($csr, 'extra_attributes', 'openssl_csr_new'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($csr, 'dn', 'openssl_csr_new'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($csr, 'privkey', 'openssl_csr_new'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($csr, 'configargs', 'openssl_csr_new'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($csr, 'extraattribs', 'openssl_csr_new'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('openssl_csr_new'));
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('openssl_csr_new'));

        $sign = BuiltinParamNames::forFunction('openssl_pkcs7_sign');
        self::assertSame(
            [
                'input_filename',
                'output_filename',
                'certificate',
                'private_key',
                'headers',
                'flags=',
                'untrusted_certificates_filename=',
            ],
            $sign
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($sign, 'input_filename', 'openssl_pkcs7_sign'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($sign, 'output_filename', 'openssl_pkcs7_sign'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($sign, 'certificate', 'openssl_pkcs7_sign'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($sign, 'private_key', 'openssl_pkcs7_sign'));
        self::assertSame(6, BuiltinParamNames::lookupNamedParamIndex($sign, 'untrusted_certificates_filename', 'openssl_pkcs7_sign'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sign, 'infile', 'openssl_pkcs7_sign'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sign, 'outfile', 'openssl_pkcs7_sign'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sign, 'signcert', 'openssl_pkcs7_sign'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sign, 'signkey', 'openssl_pkcs7_sign'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($sign, 'extracertsfilename', 'openssl_pkcs7_sign'));
        self::assertSame(7, BuiltinParamNames::paramCountForInternalFunction('openssl_pkcs7_sign'));
        self::assertSame(5, BuiltinParamNames::requiredParamCountForInternalFunction('openssl_pkcs7_sign'));
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

    /** @covers issue #23681 */
    public function testGetimagesizefromstringNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('getimagesizefromstring');
        self::assertSame(['string', 'image_info='], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'getimagesizefromstring'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'image_info', 'getimagesizefromstring'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'data', 'getimagesizefromstring'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'info', 'getimagesizefromstring'));
        self::assertSame(
            ['string', 'image_info='],
            BuiltinParamNames::paramNamesForInternalFunction('getimagesizefromstring')
        );
        self::assertSame(2, BuiltinParamNames::paramCountForInternalFunction('getimagesizefromstring'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('getimagesizefromstring'));
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

    /** @covers issue #25588 */
    public function testZlibEncodeLevelOptionalDefault(): void
    {
        $names = BuiltinParamNames::forFunction('zlib_encode');
        self::assertSame(['data', 'encoding', 'level='], $names);
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('zlib_encode'));
        self::assertSame(3, BuiltinParamNames::paramCountForInternalFunction('zlib_encode'));
        $info = ['name' => 'level', 'type' => 'int', 'isOptional' => true];
        $level = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($level, 'zlib_encode', 2, $info));
        self::assertSame(-1, $level->toInt());
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

    /** @covers issue #24812 */
    public function testJsonDecodeFlagsOptionalNamedParamsResolve(): void
    {
        self::assertSame(
            ['json', 'associative=', 'depth=', 'flags='],
            BuiltinParamNames::forFunction('json_decode')
        );
        $names = BuiltinParamNames::forFunction('json_decode');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'json', 'json_decode'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'associative', 'json_decode'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'depth', 'json_decode'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', 'json_decode'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('json_decode'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('json_decode'));
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

    /** @covers issue #24971 */
    public function testPathQueryReflectionDefaultsCluster(): void
    {
        self::assertSame(['path', 'levels='], BuiltinParamNames::forFunction('dirname'));
        self::assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('dirname'));
        self::assertSame(['path', 'suffix='], BuiltinParamNames::forFunction('basename'));
        self::assertSame(
            ['data', 'numeric_prefix=', 'arg_separator=', 'encoding_type='],
            BuiltinParamNames::forFunction('http_build_query')
        );
        self::assertSame(['string', 'length=', 'separator='], BuiltinParamNames::forFunction('chunk_split'));
        self::assertSame(['mask='], BuiltinParamNames::forFunction('umask'));
        self::assertSame(['filename', 'mtime=', 'atime='], BuiltinParamNames::forFunction('touch'));
        self::assertSame(['version1', 'version2', 'operator='], BuiltinParamNames::forFunction('version_compare'));
        self::assertSame(
            ['lifetime_or_options', 'path=', 'domain=', 'secure=', 'httponly='],
            BuiltinParamNames::forFunction('session_set_cookie_params')
        );

        $cases = [
            ['dirname', 1, 'levels', 'int', 1],
            ['basename', 1, 'suffix', 'string', ''],
            ['http_build_query', 1, 'numeric_prefix', 'string', ''],
            ['http_build_query', 3, 'encoding_type', 'int', 1],
            ['chunk_split', 1, 'length', 'int', 76],
            ['chunk_split', 2, 'separator', 'string', "\r\n"],
            ['get_html_translation_table', 1, 'flags', 'int', 11],
            ['get_html_translation_table', 2, 'encoding', 'string', 'UTF-8'],
        ];
        foreach ($cases as [$fn, $idx, $name, $type, $expected]) {
            $info = ['name' => $name, 'type' => $type, 'isOptional' => true];
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, $idx, $info, false), $fn.'#'.$idx);
            $dest = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, $fn, $idx, $info), $fn.'#'.$idx);
            if (\is_int($expected)) {
                self::assertSame($expected, $dest->toInt(), $fn.'#'.$idx);
            } else {
                self::assertSame($expected, $dest->toString(), $fn.'#'.$idx);
            }
        }

        foreach (
            [
                ['http_build_query', 2, 'arg_separator'],
                ['umask', 0, 'mask'],
                ['touch', 1, 'mtime'],
                ['touch', 2, 'atime'],
                ['version_compare', 2, 'operator'],
                ['getimagesize', 1, 'image_info'],
                ['getimagesizefromstring', 1, 'image_info'],
                ['session_set_cookie_params', 1, 'path'],
                ['session_set_cookie_params', 3, 'secure'],
                ['session_set_cookie_params', 4, 'httponly'],
            ] as [$fn, $idx, $name]
        ) {
            $info = ['name' => $name, 'type' => '', 'isOptional' => true];
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, $idx, $info, false), $fn.'#'.$idx);
            $dest = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, $fn, $idx, $info), $fn.'#'.$idx);
            self::assertSame(Variable::TYPE_NULL, $dest->type, $fn.'#'.$idx);
        }
    }

    /** @covers issue #25261 */
    public function testPhpUnameReflectionModeDefault(): void
    {
        $info = ['name' => 'mode', 'type' => 'string', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('php_uname', 0, $info, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'php_uname', 0, $info));
        self::assertSame('a', $dest->toString());
    }

    /** @covers issue #23358 */
    public function testDnsLookupReflectionNamesAndRaw(): void
    {
        foreach (['checkdnsrr', 'dns_check_record'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['hostname', 'type='], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'hostname', $fn));
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'type', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'host', $fn));
        }

        $dns = BuiltinParamNames::forFunction('dns_get_record');
        self::assertSame(
            [
                'hostname',
                'type=',
                'authoritative_name_servers=',
                'additional_records=',
                'raw=',
            ],
            $dns
        );
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($dns, 'authoritative_name_servers', 'dns_get_record'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($dns, 'additional_records', 'dns_get_record'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($dns, 'raw', 'dns_get_record'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($dns, 'authns', 'dns_get_record'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($dns, 'addtl', 'dns_get_record'));
        self::assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride('dns_get_record', 4));
        self::assertSame(5, BuiltinParamNames::paramCountForInternalFunction('dns_get_record'));

        $info = ['name' => 'type', 'type' => 'int', 'isOptional' => true];
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'dns_get_record', 1, $info));
        self::assertSame(268435456, $dest->toInt());
        $rawInfo = ['name' => 'raw', 'type' => 'bool', 'isOptional' => true];
        $rawDest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($rawDest, 'dns_get_record', 4, $rawInfo));
        self::assertFalse($rawDest->toBool());
    }

    /** @covers issue #23353 */
    public function testGetmxrrHostsWeightsReflectionNames(): void
    {
        foreach (['getmxrr', 'dns_get_mx'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['hostname', 'hosts', 'weights='], $names, $fn);
            self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'hosts', $fn));
            self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'weights', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'mxhosts', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'weight', $fn));
        }
        $info = ['name' => 'weights', 'type' => 'array', 'isOptional' => true];
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'getmxrr', 2, $info));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
    }

    /** @covers issue #24562 */
    public function testGetprotoProtocolReflectionNames(): void
    {
        foreach (['getprotobyname', 'getprotobynumber'] as $fn) {
            $names = BuiltinParamNames::forFunction($fn);
            self::assertSame(['protocol'], $names, $fn);
            self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'protocol', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'name', $fn));
            self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'proto', $fn));
        }
    }

    /** @covers issue #25453 / #28239 / #28344 */
    public function testStreamContextSetOptionsStubReturnAndOptionsType(): void
    {
        self::assertSame(
            'true',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_context_set_options')
        );
        self::assertSame(
            'true',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_context_set_params')
        );
        self::assertSame(
            'array',
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_context_set_options', 1)
        );
        self::assertNull(
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_context_set_options', 0)
        );
    }

    /**
     * streamsfuncs.stub.php — stream_context_set_option return true under PROFILE≥8.4 (#28344).
     *
     * @runInSeparateProcess
     */
    public function testStreamContextSetOptionReturnTrueUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        self::assertSame(
            'true',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_context_set_option')
        );
    }

    /** @covers issue #27739 */
    public function testStreamCopyToStreamStubReturnAndLengthType(): void
    {
        self::assertSame(
            'int|false',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_copy_to_stream')
        );
        self::assertSame(
            '?int',
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_copy_to_stream', 2)
        );
        self::assertNull(
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_copy_to_stream', 0)
        );
        self::assertNull(
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_copy_to_stream', 3)
        );
    }

    /** @covers issue #27777 */
    public function testStreamSelectStubReturnAndReadType(): void
    {
        self::assertSame(
            'int|false',
            BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_select')
        );
        self::assertSame(
            '?array',
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_select', 0)
        );
        self::assertNull(
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_select', 1)
        );
    }

    /** @covers issue #27848 */
    public function testStreamSocketClientStubReturnAndErrorOutTypes(): void
    {
        self::assertNull(
            BuiltinInternalArgInfo::returnTypeLabelForFunction('stream_socket_client')
        );
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('stream_socket_client', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('stream_socket_client', 2));
        self::assertSame('?float', BuiltinInternalArgInfo::stubParamTypeOverride('stream_socket_client', 3));
        self::assertNull(BuiltinInternalArgInfo::stubParamTypeOverride('stream_socket_client', 0));
    }

    /** @covers issue #25845 */
    public function testHashHkdfStubReturnAndStreamContextSetOptionSignature(): void
    {
        self::assertSame('string', BuiltinInternalArgInfo::returnTypeLabelForFunction('hash_hkdf'));

        self::assertSame(
            ['context', 'wrapper_or_options', 'option_name=', 'value='],
            BuiltinParamNames::forFunction('stream_context_set_option')
        );
        self::assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('stream_context_set_option'));
        self::assertSame(4, BuiltinParamNames::paramCountForInternalFunction('stream_context_set_option'));
        self::assertSame(
            'array|string',
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_context_set_option', 1)
        );
        self::assertSame(
            '?string',
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_context_set_option', 2)
        );
        self::assertSame(
            'mixed',
            BuiltinInternalArgInfo::stubParamTypeOverride('stream_context_set_option', 3)
        );
        self::assertSame([], BuiltinByRefParams::forFunction('stream_context_set_option'));

        $info3 = BuiltinInternalArgInfo::paramInfoForFunction('stream_context_set_option', 3);
        self::assertNotNull($info3);
        self::assertSame('mixed', $info3['type']);
        // UNKNOWN default: optional via name `=`, but not materializable for Reflection (#25845)
        self::assertFalse(
            BuiltinInternalDefaultValues::isAvailable(
                'stream_context_set_option',
                3,
                ['name' => 'value', 'type' => 'mixed', 'isOptional' => true],
                false
            )
        );
    }

    /** @covers issue #25381 */
    public function testHeaderAndStreamContextStubParamTypes(): void
    {
        self::assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride('header_remove', 0));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('headers_sent', 0));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('headers_sent', 1));
        self::assertSame('callable', BuiltinInternalArgInfo::stubParamTypeOverride('header_register_callback', 0));
        self::assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride('stream_context_get_default', 0));

        self::assertSame('?string', BuiltinInternalArgInfo::paramInfoForFunction('header_remove', 0)['type']);
        self::assertSame('', BuiltinInternalArgInfo::paramInfoForFunction('headers_sent', 0)['type']);
        self::assertSame('', BuiltinInternalArgInfo::paramInfoForFunction('headers_sent', 1)['type']);
        self::assertSame('callable', BuiltinInternalArgInfo::paramInfoForFunction('header_register_callback', 0)['type']);
        self::assertSame('?array', BuiltinInternalArgInfo::paramInfoForFunction('stream_context_get_default', 0)['type']);
    }

    /** @covers issue #24665 — php-src ext/ldap/ldap.stub.php names (not InternalArgInfo link/host/base_dn) */
    public function testLdapBindSearchConnectNamedParamsMatchZendStub(): void
    {
        $bind = BuiltinParamNames::forFunction('ldap_bind');
        self::assertSame(['ldap', 'dn=', 'password='], $bind);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($bind, 'ldap', 'ldap_bind'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($bind, 'dn', 'ldap_bind'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($bind, 'password', 'ldap_bind'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($bind, 'link', 'ldap_bind'));
        self::assertSame(
            ['ldap', 'dn=', 'password='],
            BuiltinParamNames::paramNamesForInternalFunction('ldap_bind')
        );

        $search = BuiltinParamNames::forFunction('ldap_search');
        self::assertSame(
            [
                'ldap',
                'base',
                'filter',
                'attributes=',
                'attributes_only=',
                'sizelimit=',
                'timelimit=',
                'deref=',
                'controls=',
            ],
            $search
        );
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($search, 'ldap', 'ldap_search'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($search, 'base', 'ldap_search'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($search, 'attributes', 'ldap_search'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($search, 'attributes_only', 'ldap_search'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($search, 'link', 'ldap_search'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($search, 'base_dn', 'ldap_search'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($search, 'attrs', 'ldap_search'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($search, 'attrsonly', 'ldap_search'));
        self::assertSame($search, BuiltinParamNames::forFunction('ldap_list'));
        self::assertSame($search, BuiltinParamNames::forFunction('ldap_read'));

        $connect = BuiltinParamNames::forFunction('ldap_connect');
        self::assertSame(['uri=', 'port='], $connect);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($connect, 'uri', 'ldap_connect'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($connect, 'port', 'ldap_connect'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($connect, 'host', 'ldap_connect'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($connect, 'wallet', 'ldap_connect'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($connect, 'wallet_passwd', 'ldap_connect'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($connect, 'authmode', 'ldap_connect'));
        self::assertSame(
            ['uri=', 'port='],
            BuiltinParamNames::paramNamesForInternalFunction('ldap_connect')
        );
    }

}
