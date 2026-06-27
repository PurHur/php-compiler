<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\TypedFunctionStaticRewriter;
use PHPUnit\Framework\TestCase;

final class TypedFunctionStaticRewriterTest extends TestCase
{
    public function testRewritesTypedFunctionStatic(): void
    {
        $src = <<<'PHP'
<?php
function f(): void {
    static int $n = 0;
}
PHP;
        $out = TypedFunctionStaticRewriter::rewrite($src);
        self::assertStringContainsString('/*phpc-typed-function-static:int*/ $n', preg_replace('/\s+/', ' ', $out));
        self::assertStringNotContainsString('static int $n', $out);
    }

    public function testDoesNotRewriteStaticProperty(): void
    {
        $src = <<<'PHP'
<?php
class C { public static int $p = 1; }
PHP;
        $out = TypedFunctionStaticRewriter::rewrite($src);
        self::assertStringNotContainsString('phpc-typed-function-static', $out);
        self::assertStringContainsString('public static int $p', $out);
    }

    public function testDoesNotRewriteAsymmetricStaticProperty(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility disabled on reference profile (#12508)');
        }
        $src = <<<'PHP'
<?php
class C {
    private(set) static int $sx = 1;
}
PHP;
        $out = TypedFunctionStaticRewriter::rewrite($src);
        self::assertStringNotContainsString('phpc-typed-function-static', $out);
        self::assertStringContainsString('private(set) static int $sx', preg_replace('/\s+/', ' ', $out));
    }

    public function testRewritesTypedFunctionStaticInsideClassMethod(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public static function m(): int {
        static int $n = 0;
        return ++$n;
    }
}
PHP;
        $out = TypedFunctionStaticRewriter::rewrite($src);
        self::assertStringContainsString('/*phpc-typed-function-static:int*/ $n', preg_replace('/\s+/', ' ', $out));
        self::assertSame(1, substr_count($out, 'phpc-typed-function-static'));
    }

    public function testDoesNotRewriteStaticCall(): void
    {
        $src = <<<'PHP'
<?php
class C { public static function m(): void {} }
C::m();
PHP;
        $out = TypedFunctionStaticRewriter::rewrite($src);
        self::assertStringNotContainsString('phpc-typed-function-static', $out);
    }
}
