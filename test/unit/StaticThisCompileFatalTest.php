<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32181 — static $this is a Zend compile-time fatal */
final class StaticThisCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalStaticThisProvider
     */
    public function testStaticThisFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use $this as static variable');
        $runtime->parseAndCompile($code, 'static_this.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalStaticThisProvider(): iterable
    {
        yield 'function' => ['<?php function foo() { static $this; } echo "accepted\n";'];
        yield 'function with init' => ['<?php function foo() { static $this = 1; } echo "accepted\n";'];
        yield 'method' => [
            '<?php class C { public function m() { static $this; echo "accepted\n"; } } (new C())->m();',
        ];
        yield 'closure' => ['<?php $f = function () { static $this; }; echo "accepted\n";'];
        yield 'file scope' => ['<?php static $this; echo "accepted\n";'];
    }

    public function testLegalFunctionStaticStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php function foo() { static $n = 0; $n++; return $n; } echo foo(); echo foo();',
            'static_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('12', ob_get_clean());
    }
}
