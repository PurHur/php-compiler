<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: appendChild(createTextNode) refreshes Element textContent/nodeValue (#35300).
 *
 * php-src: ext/dom/node.c — dom_node_textContent_read / xmlNodeGetContent.
 */
final class DomAppendTextTextContent35300AotTest extends TestCase
{
    public function testRebuildRefreshesTextContentSlots(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomAppendChildLiveSlots.php');
        $this->assertStringContainsString('refreshTextContentSlotsFromChildren', $src);
        $this->assertStringContainsString('#35300', $src);
    }

    public function testAotAppendTextTextContentMatchesZend(): void
    {
        $repro = dirname(__DIR__).'/repro/dom_append_text_textcontent_aot.php';
        $this->assertFileExists($repro);
        $zend = $this->runPhp($repro);
        $bin = sys_get_temp_dir().'/dom_append_tc_35300_'.getmypid();
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
        $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__, 2), [
            'PHP_COMPILER_LLVM_PATH' => getenv('PHP_COMPILER_LLVM_PATH') ?: '/opt/llvm9',
        ]);
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
