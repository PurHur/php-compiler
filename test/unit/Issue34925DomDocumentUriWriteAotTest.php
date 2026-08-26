<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMDocument::$documentURI write+read match Zend (#34925 leftover of #34919).
 *
 * @group llvm
 * @group aot
 */
final class Issue34925DomDocumentUriWriteAotTest extends TestCase
{
    public function testDocumentUriWriteReadMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34925_dom_documenturi_write_aot.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);

        $bin = sys_get_temp_dir().'/phpc_34925_'.getmypid().'.bin';
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

    public function testDocumentUriSeededNotMetaHardcoded(): void
    {
        $construct = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/DomDocumentConstruct.php');
        $meta = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomDocumentMetaProps.php');
        $object = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $load = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomLoadXMLUserScript.php');
        $this->assertStringContainsString('seedDocumentUriNull', $construct);
        $this->assertStringContainsString('#34925', $construct);
        $this->assertStringContainsString('PROP_DOCUMENT_URI', $object);
        $this->assertStringContainsString('markPropertyWriteReject', $object);
        $this->assertStringContainsString('storeDocumentUriCwd', $load);
        $this->assertStringNotContainsString("'documenturi' === \$propLc", $meta);
        $this->assertStringContainsString('PROP_DOCUMENT_URI', $meta);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/dom_documenturi.c');
    }
}
