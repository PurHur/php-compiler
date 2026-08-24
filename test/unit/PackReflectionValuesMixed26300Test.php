<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * pack() Reflection advertises mixed ...$values (#26300).
 *
 * @see php-src ext/standard/basic_functions.stub.php
 */
final class PackReflectionValuesMixed26300Test extends TestCase
{
    public function testPackValuesParameterIsMixedVariadic(): void
    {
        $r = new \ReflectionFunction('pack');
        $params = $r->getParameters();
        self::assertCount(2, $params);
        self::assertSame('format', $params[0]->getName());
        self::assertSame('string', (string) $params[0]->getType());
        self::assertFalse($params[0]->isVariadic());
        self::assertSame('values', $params[1]->getName());
        self::assertTrue($params[1]->hasType());
        self::assertSame('mixed', (string) $params[1]->getType());
        self::assertTrue($params[1]->isVariadic());
        self::assertSame('string', (string) $r->getReturnType());
    }

    public function testPackRuntimeStillWorks(): void
    {
        self::assertSame('010203', bin2hex(pack('C*', 1, 2, 3)));
    }
}
