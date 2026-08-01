<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPTypes\Type;

/**
 * Psalm/PHPStan quoted string literals in phpdoc (#26686) — unblocks bootstrap-selfhost-link
 * after #26655 added `@return null|'parent'|'self'|'static'` on Compiler.
 */
final class PhpTypesFromDeclStringLiteralsPatchTest extends TestCase
{
    public function testFromDeclAcceptsQuotedStringLiterals(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php')) {
            $this->markTestSkipped('vendor/ircmaxell/php-types not installed');
        }

        foreach (["'parent'", '"self"', "''"] as $decl) {
            $type = Type::fromDecl($decl);
            $this->assertSame(Type::TYPE_STRING, $type->type, 'decl='.$decl);
        }

        $union = Type::fromDecl("null|'parent'|'self'|'static'");
        $this->assertContains($union->type, [Type::TYPE_UNION, Type::TYPE_STRING, Type::TYPE_NULL]);
        // Simplified union should not throw and should be usable.
        $this->assertInstanceOf(Type::class, $union->simplify());
    }
}
