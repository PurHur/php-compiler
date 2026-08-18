<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32205 — foreach (... as &$this) is a Zend compile-time fatal */
final class ForeachByRefThisCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalForeachThisProvider
     */
    public function testForeachThisFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot re-assign $this');
        $runtime->parseAndCompile($code, 'foreach_this.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalForeachThisProvider(): iterable
    {
        yield 'by-ref method' => [
            '<?php class C { public function m() { foreach ([1] as &$this) { echo "accepted\n"; } } } (new C())->m();',
        ];
        yield 'by-ref function' => ['<?php function foo() { foreach ([1] as &$this) { echo "accepted\n"; } } foo();'];
        yield 'by-ref file scope' => ['<?php foreach ([1] as &$this) { echo "accepted\n"; }'];
        yield 'by-ref keyed' => [
            '<?php class C { public function m() { foreach ([1] as $k => &$this) { echo "accepted\n"; } } } (new C())->m();',
        ];
        yield 'value-form method' => [
            '<?php class C { public function m() { foreach ([1] as $this) { echo "accepted\n"; } } } (new C())->m();',
        ];
        yield 'value-form file scope' => ['<?php foreach ([1] as $this) { echo "accepted\n"; }'];
    }

    public function testLegalForeachByRefStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $a = [1, 2]; foreach ($a as &$v) { $v++; } echo $a[0], $a[1];',
            'foreach_byref_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('23', ob_get_clean());
    }

    public function testForeachByRefThisPropertyStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { public $x = 0; public function m() { foreach ([9] as &$this->x) {} echo $this->x; } } (new C())->m();',
            'foreach_this_prop_ok.php'
        );
        $this->assertNotNull($block);
    }
}
