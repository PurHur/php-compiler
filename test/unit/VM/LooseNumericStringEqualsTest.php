<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Zend zend_operators.c numeric-string loose == (#3644). */
final class LooseNumericStringEqualsTest extends TestCase
{
    private static function intVar(int $value): Variable
    {
        $v = new Variable(Variable::TYPE_INTEGER);
        $v->int($value);

        return $v;
    }

    private static function strVar(string $value): Variable
    {
        $v = new Variable(Variable::TYPE_STRING);
        $v->string($value);

        return $v;
    }

    public function testNonNumericStringEqualsZero(): void
    {
        $this->assertTrue(self::intVar(0)->equals(self::strVar('a')));
        $this->assertTrue(self::strVar('a')->equals(self::intVar(0)));
    }

    public function testNumericStringEqualsParsedValue(): void
    {
        $this->assertTrue(self::intVar(0)->equals(self::strVar('0')));
        $this->assertTrue(self::intVar(1)->equals(self::strVar('1')));
        $this->assertFalse(self::intVar(1)->equals(self::strVar('2')));
    }

    public function testEmptyStringNotLooselyEqualToInteger(): void
    {
        $this->assertFalse(self::intVar(0)->equals(self::strVar('')));
        $this->assertFalse(self::strVar('')->equals(self::intVar(0)));
        $this->assertFalse(self::intVar(1)->equals(self::strVar('')));
    }

    public function testScientificNotationStringLooselyEqualToIntegerWhenCoercedToZero(): void
    {
        $this->assertTrue(self::intVar(0)->equals(self::strVar('0e5')));
        $this->assertTrue(self::strVar('0e5')->equals(self::intVar(0)));
        $this->assertTrue(self::intVar(0)->equals(self::strVar('0e123')));
        $this->assertTrue(self::strVar('0e123')->equals(self::intVar(0)));
        $this->assertFalse(self::intVar(1)->equals(self::strVar('1abc')));
    }
}
