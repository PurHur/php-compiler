<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: cloneNode after createElement+appendChild (no loadXML) (#35361).
 *
 * php-src: ext/dom/node.c — php_dom_clone_node → xmlDocCopyNode.
 */
final class DomCloneNodeCreateElement35361AotTest extends TestCase
{
    public function testCloneNodeFallsBackToCreateElementMetadata(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomCloneNode.php');
        $this->assertStringContainsString('specFromCreateElementReceiver', $src);
        $this->assertStringContainsString('#35361', $src);
    }

    public function testAppendKeepsCompileTimeInnerXmlForClone(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/DomNodeLiveMutationRuntime.php'
        );
        $this->assertStringContainsString('createElement trees can cloneNode', $src);
        $this->assertStringContainsString('#35361', $src);
    }

    public function testAotCloneNodeCreateElementMatchesZend(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_dom_clonenode_createelement.php';
        $this->assertFileExists($repro);
        $zend = $this->runPhp($repro);
        $bin = sys_get_temp_dir().'/dom_clonenode_ce_35361_'.getmypid();
        $compile = $this->runCmd([
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/compile.php',
            '-o',
            $bin,
            $repro,
        ]);
        $this->assertSame(0, $compile['code'], $compile['out']);
        $this->assertFileExists($bin);
        try {
            $aot = $this->runCmd([$bin]);
            $this->assertSame(0, $aot['code'], $aot['out']);
            $this->assertSame($zend, $aot['out']);
        } finally {
            @unlink($bin);
        }
    }

    /** @return array{code:int,out:string} */
    private function runCmd(array $cmd): array
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'out' => $out];
    }

    private function runPhp(string $file): string
    {
        $r = $this->runCmd([PHP_BINARY, $file]);
        $this->assertSame(0, $r['code'], $r['out']);

        return $r['out'];
    }
}
