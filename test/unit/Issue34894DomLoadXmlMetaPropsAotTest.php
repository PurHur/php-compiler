<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML must materialize document meta props (#34894).
 *
 * @group llvm
 * @group aot
 */
final class Issue34894DomLoadXmlMetaPropsAotTest extends TestCase
{
    public function testLoadXmlMetaPropsMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34894_dom_loadxml_meta_props_aot.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);

        $bin = sys_get_temp_dir().'/phpc_34894_'.getmypid().'.bin';
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
        $this->assertSame(0, $runRc, 'aot run failed: '.implode("\n", $out));
        $this->assertSame(implode("\n", $expected), implode("\n", $out));
    }

    public function testMetaPropsPinnedInAllocateAndFetched(): void
    {
        $obj = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('PROP_XML_VERSION', $obj);
        $this->assertStringContainsString('PROP_DOCUMENT_URI', $obj);
        $this->assertStringContainsString('#34894', $obj);
        $load = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomLoadXMLUserScript.php');
        $this->assertStringContainsString('storeMetaDocumentProperties', $load);
        $meta = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomDocumentMetaProps.php');
        $this->assertStringContainsString('#34894', $meta);
    }
}
