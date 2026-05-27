<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPTypes\Type;

final class PhpTypesFromDeclJunkFragmentsPatchTest extends TestCase
{
    public function testFromDeclToleratesMalformedDocFragments(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php')) {
            $this->markTestSkipped('vendor/ircmaxell/php-types not installed');
        }

        foreach (['*/', '*'] as $decl) {
            $type = Type::fromDecl($decl);
            $this->assertInstanceOf(Type::class, $type);
            $this->assertNotSame(Type::TYPE_OBJECT, $type->type, 'decl='.$decl);
        }

        $this->expectException(\RuntimeException::class);
        Type::fromDecl('');

        $trailing = Type::fromDecl('PHPTypes\\Type The type');
        $this->assertSame(Type::TYPE_OBJECT, $trailing->type);
        $this->assertSame('PHPTypes\\Type', $trailing->name);
    }
}
