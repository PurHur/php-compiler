<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMNode::$nodeType seeded on thin-AOT materialize (#33607).
 *
 * @group llvm
 */
final class DomNodeType33607AotTest extends TestCase
{
    public function testNodeTypeMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_33607_dom_nodetype_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame('9|1|2|3|8', trim($aot));
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'phpc33607_');
        $this->assertNotFalse($bin);
        @unlink($bin);
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../../bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $cout, $ccode);
        $this->assertSame(0, $ccode, implode("\n", $cout));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $code);
        @unlink($bin);
        $this->assertSame(0, $code, implode("\n", $out));

        return implode("\n", $out);
    }
}
