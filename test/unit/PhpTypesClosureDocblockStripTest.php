<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPTypes\Type;
use PHPUnit\Framework\TestCase;

/**
 * Composer ClassLoader documents {@code @var \Closure(string):void}; php-types must not fatal (#36382).
 */
final class PhpTypesClosureDocblockStripTest extends TestCase
{
    public function testClosureSignatureDocblockBecomesClosureObject(): void
    {
        $type = Type::fromDecl('\\Closure(string):void');
        $this->assertSame('Closure', (string) $type);
    }

    public function testCallableSignatureDocblockStaysCallable(): void
    {
        $type = Type::fromDecl('callable(string):void');
        $this->assertSame('callable', (string) $type);
    }
}
