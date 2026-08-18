<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32152 — closure use($this) is a Zend compile-time fatal */
final class ClosureUseThisCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalUseThisProvider
     */
    public function testUseThisFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use $this as lexical variable');
        $runtime->parseAndCompile($code, 'closure_use_this.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalUseThisProvider(): iterable
    {
        yield 'file scope' => ['<?php $f = function () use ($this) { return 1; }; echo "accepted\n";'];
        yield 'by-ref' => ['<?php $f = function () use (&$this) { return 1; }; echo "accepted\n";'];
        yield 'method scope' => [
            '<?php class C { public function m() { $f = function () use ($this) { return 1; }; echo "accepted\n"; } } (new C())->m();',
        ];
        yield 'mixed uses' => ['<?php $a = 1; $f = function () use ($a, $this) { return $a; }; echo "accepted\n";'];
    }

    public function testLegalUseStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $a = 2; $f = function () use ($a) { return $a; }; echo $f();',
            'closure_use_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('2', ob_get_clean());
    }

    public function testMethodClosureAutoBindsThisWithoutUse(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { public int $n = 7; public function m() { $f = function () { return $this->n; }; echo $f(); } } (new C())->m();',
            'closure_this_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('7', ob_get_clean());
    }
}
