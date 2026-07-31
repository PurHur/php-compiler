<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ClassConstName;
use PHPUnit\Framework\TestCase;

/** Class constant name case matching (#25910). */
final class ClassConstNameTest extends TestCase
{
    public function testExactDeclaredMatch(): void
    {
        self::assertTrue(ClassConstName::matchesDeclared('X', 'X'));
        self::assertFalse(ClassConstName::matchesDeclared('x', 'X'));
        self::assertFalse(ClassConstName::matchesDeclared('X', 'x'));
    }

    public function testMissingDeclaredAccepts(): void
    {
        self::assertTrue(ClassConstName::matchesDeclared('X', null));
        self::assertTrue(ClassConstName::matchesDeclared('x', null));
    }
}
