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
    }
}
