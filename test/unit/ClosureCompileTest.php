<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Compiler + VM smoke for anonymous closures (#72, #142). */
final class ClosureCompileTest extends TestCase
{
    public function testPhpcRunClosureInline(): void
    {
        $repo = dirname(__DIR__, 2);
        $cmd = 'cd '.escapeshellarg($repo)
            .' && ./phpc run -r '.escapeshellarg('$f = function($x) { return $x + 1; }; echo $f(2);')
            .' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('3', implode("\n", $out));
    }

    public function testPhpcRunArrowInline(): void
    {
        $repo = dirname(__DIR__, 2);
        $cmd = 'cd '.escapeshellarg($repo)
            .' && ./phpc run -r '.escapeshellarg('$f = fn($x) => $x * 2; echo $f(3);')
            .' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('6', implode("\n", $out));
    }

    /** Regression: Closure::bind(inline closure, ...) must send closure not hoisted C::class (#3673). */
    public function testVmClosureBindStaticInlineClosure(): void
    {
        $code = <<<'PHP'
<?php
class C { private function sec(): string { return 'ok'; } }
$c = new C;
$f = Closure::bind(function (): string { return $this->sec(); }, $c, C::class);
echo $f(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
