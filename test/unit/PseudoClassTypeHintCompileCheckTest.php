<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\PseudoClassTypeHintCompileCheck;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPUnit\Framework\TestCase;

/** @covers issue #17480 — self/parent/static type hints outside class scope */
final class PseudoClassTypeHintCompileCheckTest extends TestCase
{
    /**
     * @dataProvider pseudoClassTypeProvider
     */
    public function testFindsPseudoClassKeywordInReturnType(string $keyword): void
    {
        $type = new Op\Type\Literal($keyword);
        self::assertSame(strtolower($keyword), PseudoClassTypeHintCompileCheck::findKeyword($type));
    }

    /** @return iterable<string, array{string}> */
    public static function pseudoClassTypeProvider(): iterable
    {
        yield 'self' => ['self'];
        yield 'parent' => ['parent'];
        yield 'static' => ['static'];
    }

    public function testFindsKeywordInNullableUnion(): void
    {
        $type = new Op\Type\Union_([
            new Op\Type\Literal('int'),
            new Op\Type\Literal('static'),
        ]);
        self::assertSame('static', PseudoClassTypeHintCompileCheck::findKeyword($type));
    }

    public function testMessageMatchesZend(): void
    {
        self::assertSame(
            'Cannot use "static" when no class scope is active',
            PseudoClassTypeHintCompileCheck::messageFor('static')
        );
    }

    public function testReferenceTypeDeclaration(): void
    {
        $decl = new Operand\Literal('Self');
        $type = new Op\Type\Reference($decl);
        self::assertSame('self', PseudoClassTypeHintCompileCheck::findKeyword($type));
    }

    public function testContainsKeywordFindsParentInUnion(): void
    {
        $type = new Op\Type\Union_([
            new Op\Type\Literal('int'),
            new Op\Type\Reference(new Operand\Literal('parent')),
        ]);
        self::assertTrue(PseudoClassTypeHintCompileCheck::containsKeyword($type, 'parent'));
        self::assertFalse(PseudoClassTypeHintCompileCheck::containsKeyword($type, 'self'));
    }
}
