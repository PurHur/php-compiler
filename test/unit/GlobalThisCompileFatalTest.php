<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32180 — global $this is a Zend compile-time fatal */
final class GlobalThisCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalGlobalThisProvider
     */
    public function testGlobalThisFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use $this as global variable');
        $runtime->parseAndCompile($code, 'global_this.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalGlobalThisProvider(): iterable
    {
        yield 'function scope' => ['<?php function foo() { global $this; } echo "accepted\n";'];
        yield 'method scope' => [
            '<?php class C { public function m() { global $this; } } echo "accepted\n";',
        ];
        yield 'file scope' => ['<?php global $this; echo "accepted\n";'];
        yield 'mixed names' => ['<?php function foo() { global $a, $this; } echo "accepted\n";'];
    }

    public function testLegalGlobalStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php function foo() { global $a; $a = 3; } foo(); echo $a;',
            'global_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('3', ob_get_clean());
    }
}
