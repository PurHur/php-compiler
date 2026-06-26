<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\VM\CompareStringableHelper;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\ClassEntry;
use PHPUnit\Framework\TestCase;

/** Stringable object loose compare helper (#12055, Zend/zend_compare.c). */
final class CompareStringableHelperTest extends TestCase
{
    public function testIsObjectStringPair(): void
    {
        $object = self::objectVar();
        $string = self::strVar('needle');
        $this->assertTrue(CompareStringableHelper::isObjectStringPair($object, $string));
        $this->assertTrue(CompareStringableHelper::isObjectStringPair($string, $object));
        $this->assertFalse(CompareStringableHelper::isObjectStringPair($object, $object));
        $this->assertFalse(CompareStringableHelper::isObjectStringPair($string, $string));
    }

    public function testLooseEqualWithoutVmIsFalseForObjectString(): void
    {
        $this->assertFalse(CompareStringableHelper::looseEqual(
            null,
            self::objectVar(),
            self::strVar('needle')
        ));
    }

    public function testLooseEqualReturnsNullForUnrelatedOperands(): void
    {
        $this->assertNull(CompareStringableHelper::looseEqual(
            null,
            self::strVar('a'),
            self::strVar('b')
        ));
    }

    public function testSpaceshipWithoutVmOrdersObjectAboveString(): void
    {
        $object = self::objectVar();
        $string = self::strVar('needle');
        $this->assertSame(1, CompareStringableHelper::spaceship(null, $object, $string));
        $this->assertSame(-1, CompareStringableHelper::spaceship(null, $string, $object));
    }

    private static function objectVar(): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object(new ObjectEntry(new ClassEntry('S')));

        return $var;
    }

    private static function strVar(string $value): Variable
    {
        $var = new Variable(Variable::TYPE_STRING);
        $var->string($value);

        return $var;
    }
}
