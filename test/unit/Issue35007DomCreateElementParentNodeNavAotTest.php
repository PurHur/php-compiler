<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: createElement ParentNode nav must match Zend (#35007 leftover of #34910).
 *
 * @group llvm
 * @group aot
 */
final class Issue35007DomCreateElementParentNodeNavAotTest extends TestCase
{
    public function testCreateElementParentNodeNavMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_dom_createelement_parentnode_nav.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);

        $bin = sys_get_temp_dir().'/phpc_35007_'.getmypid().'.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $cmd = [PHP_BINARY, $root.'/bin/compile.php', '-o', $bin, $src];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileRc = proc_close($proc);
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 500));

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $out));
        $this->assertSame(implode("\n", $expected), implode("\n", $out));
    }

    public function testElementParentNodePropsPinnedAndSeeded(): void
    {
        $obj = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $create = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomCreateElement.php');
        $append = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomAppendChildLiveSlots.php');
        $this->assertStringContainsString('PROP_FIRST_ELEMENT_CHILD', $obj);
        $this->assertStringContainsString('#35007', $obj);
        $this->assertStringContainsString('seedEmptyParentNodeNavigation', $create);
        $this->assertStringContainsString('syncParentNodeNavOnAppend', $append);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/dom_parentnode.c');
    }
}
