<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32251 — declared const class is a Zend compile-time fatal */
final class ClassConstNamedClassCompileFatalTest extends TestCase
{
    private const MESSAGE = "A class constant must not be called 'class'; it is reserved for class name fetching";

    /**
     * @dataProvider illegalClassConstClassProvider
     */
    public function testReservedClassConstNameFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(self::MESSAGE);
        $runtime->parseAndCompile($code, 'class_const_class.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalClassConstClassProvider(): iterable
    {
        yield 'class const class' => ['<?php class Foo { const class = 1; } echo "accepted\n";'];
        yield 'class const CLASS' => ['<?php class Foo { const CLASS = 1; } echo "accepted\n";'];
        yield 'class const Class' => ['<?php class Foo { const Class = 1; } echo "accepted\n";'];
        yield 'interface const class' => ['<?php interface I { const class = 1; } echo "accepted\n";'];
        yield 'trait const class' => ['<?php trait T { const class = 1; } echo "accepted\n";'];
        yield 'enum const class' => ['<?php enum E { const class = 1; } echo "accepted\n";'];
        yield 'public const class' => ['<?php class Foo { public const class = 1; } echo "accepted\n";'];
    }

    public function testClassPseudoConstantStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class Foo {} echo Foo::class, "\n", Foo::CLASS;',
            'class_pseudo_const_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("Foo\nFoo", ob_get_clean());
    }

    public function testLegalClassConstStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class Foo { const BAR = 1; } echo Foo::BAR, Foo::class;',
            'class_const_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1Foo', ob_get_clean());
    }
}
