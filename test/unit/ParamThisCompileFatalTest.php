<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32179 — $this as a parameter is a Zend compile-time fatal */
final class ParamThisCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalParamThisProvider
     */
    public function testParamThisFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use $this as parameter');
        $runtime->parseAndCompile($code, 'param_this.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalParamThisProvider(): iterable
    {
        yield 'function' => ['<?php function foo($this) {} echo "accepted\n";'];
        yield 'by-ref' => ['<?php function foo(&$this) {} echo "accepted\n";'];
        yield 'arrow' => ['<?php $f = fn($this) => 1; echo "accepted\n";'];
        yield 'method' => ['<?php class C { function m($this) {} } echo "accepted\n";'];
        yield 'abstract method' => ['<?php abstract class C { abstract function m($this); } echo "accepted\n";'];
        yield 'closure' => ['<?php $f = function ($this) { return 1; }; echo "accepted\n";'];
        yield 'mixed params' => ['<?php function foo($a, $this) {} echo "accepted\n";'];
    }

    public function testLegalParamStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php function foo($that) { return $that; } echo foo(4);',
            'param_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('4', ob_get_clean());
    }

    public function testMethodThisStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { public int $n = 9; public function m() { echo $this->n; } } (new C())->m();',
            'method_this_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('9', ob_get_clean());
    }
}
