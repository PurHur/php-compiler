<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Zend spaceship on unlike scalars (#4681, zend_operators.c). */
final class SpaceshipMixedScalarTest extends TestCase
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

    public function testIntNonNumericStringSpaceship(): void
    {
        $this->assertSame(-1, Variable::spaceshipCompare(self::intVar(1), self::strVar('b')));
        $this->assertSame(1, Variable::spaceshipCompare(self::strVar('b'), self::intVar(1)));
    }

    public function testIntNumericStringSpaceship(): void
    {
        $this->assertSame(-1, Variable::spaceshipCompare(self::intVar(1), self::strVar('2')));
        $this->assertSame(0, Variable::spaceshipCompare(self::intVar(1), self::strVar('1')));
    }
}
