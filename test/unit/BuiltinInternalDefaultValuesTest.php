<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinInternalDefaultValues;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers BuiltinInternalDefaultValues */
final class BuiltinInternalDefaultValuesTest extends TestCase
{
    public function testArrayObjectConstructDefaults(): void
    {
        $info = ['name' => 'array', 'type' => '', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('arrayobject::__construct', 0, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'arrayobject::__construct', 0, $info)
        );
        self::assertSame(Variable::TYPE_ARRAY, $var->type);
        self::assertSame(0, $var->toArray()->getNumElements());

        $flags = ['name' => 'flags', 'type' => 'int', 'isOptional' => true];
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'arrayobject::__construct', 1, $flags)
        );
        self::assertSame(0, $var->toInt());

        $iter = ['name' => 'iterator_class', 'type' => 'string', 'isOptional' => true];
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'arrayobject::__construct', 2, $iter)
        );
        self::assertSame('ArrayIterator', $var->toString());
    }

    public function testOptionalWithoutReflectionDefaultIsUnavailable(): void
    {
        $info = ['name' => 'userdata', 'type' => '', 'isOptional' => true];
        self::assertFalse(
            BuiltinInternalDefaultValues::isAvailable('array_walk', 2, $info, false)
        );
    }

    public function testStrReplaceCountDefaultIsNull(): void
    {
        $info = ['name' => 'count', 'type' => 'int', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('str_replace', 3, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'str_replace', 3, $info)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);

        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('str_ireplace', 3, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'str_ireplace', 3, $info)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);

        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('str_replace', 3));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('str_ireplace', 3));

        // #23587 — ext/pcre/php_pcre.stub.php unions + untyped &$count
        self::assertSame('array|string', BuiltinInternalArgInfo::stubParamTypeOverride('preg_replace', 0));
        self::assertSame('array|string', BuiltinInternalArgInfo::stubParamTypeOverride('preg_filter', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('preg_replace', 4));
        self::assertSame('callable', BuiltinInternalArgInfo::stubParamTypeOverride('preg_replace_callback', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('preg_replace_callback', 4));
    }

    /** @covers issue #24896 */
    public function testUnpackOffsetDefaultIsZero(): void
    {
        $info = ['name' => 'offset', 'type' => '', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('unpack', 2, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'unpack', 2, $info)
        );
        self::assertSame(0, $var->toInt());
    }

    /** @covers issue #24968 */
    public function testSetcookieStringDefaultsAreEmpty(): void
    {
        $info = ['name' => 'value', 'type' => 'string', 'isOptional' => true];
        foreach (['setcookie', 'setrawcookie'] as $fn) {
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 1, $info, false));
            $var = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($var, $fn, 1, $info));
            self::assertSame('', $var->toString());

            $path = ['name' => 'path', 'type' => 'string', 'isOptional' => true];
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 3, $path, false));
            $var = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($var, $fn, 3, $path));
            self::assertSame('', $var->toString());

            $domain = ['name' => 'domain', 'type' => 'string', 'isOptional' => true];
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 4, $domain, false));
            $var = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($var, $fn, 4, $domain));
            self::assertSame('', $var->toString());
        }
    }
}
