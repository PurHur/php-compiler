<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\TypedFunctionStaticRewriter;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\TypedFunctionStaticSyntaxRejector;
use PHPUnit\Framework\TestCase;

final class TypedFunctionStaticRewriterTest extends TestCase
{
    /** @var string|false */
    private $prevProfile;

    protected function setUp(): void
    {
        $this->prevProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
    }

    protected function tearDown(): void
    {
        if (false === $this->prevProfile || '' === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
        }
    }

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
        $src = <<<'PHP'
<?php
class C {
    public (private(set)) static int $sx = 1;
}
PHP;
        $out = TypedFunctionStaticRewriter::rewrite($src);
        self::assertStringNotContainsString('phpc-typed-function-static', $out);
        self::assertStringContainsString('public (private(set)) static int $sx', preg_replace('/\s+/', ' ', $out));
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

    public function testReferenceProfileSyntaxErrorDetectsTypedStatic(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        $src = <<<'PHP'
<?php
function f(): int {
    static int $x = 0;
    return ++$x;
}
PHP;
        $error = TypedFunctionStaticRewriter::referenceProfileSyntaxError($src);
        self::assertNotNull($error);
        self::assertSame(3, $error['line']);
        self::assertSame('syntax error, unexpected identifier "int", expecting "::"', $error['message']);
    }

    public function testReferenceProfileSyntaxRejectorThrows(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        $src = <<<'PHP'
<?php
function f(): int {
    static int $x = 0;
    return ++$x;
}
PHP;
        try {
            TypedFunctionStaticSyntaxRejector::reject($src, 'repro.php');
            self::fail('expected CompileFatal');
        } catch (CompileFatal $e) {
            self::assertSame('syntax error, unexpected identifier "int", expecting "::"', $e->getMessage());
        }
    }
}
