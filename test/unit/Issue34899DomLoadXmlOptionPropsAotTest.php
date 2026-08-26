<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML then document option props must match Zend (#34899 leftover of #34894).
 *
 * @group llvm
 * @group aot
 */
final class Issue34899DomLoadXmlOptionPropsAotTest extends TestCase
{
    public function testLoadXmlOptionPropsMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34899_dom_loadxml_option_props_aot.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);

        $bin = sys_get_temp_dir().'/phpc_34899_'.getmypid().'.bin';
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

    public function testOptionPropsPinnedAndFetched(): void
    {
        $obj = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $meta = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomDocumentMetaProps.php');
        $this->assertStringContainsString('PROP_STRICT_ERROR_CHECKING', $obj);
        $this->assertStringContainsString('PROP_FORMAT_OUTPUT', $obj);
        $this->assertStringContainsString('#34899', $obj);
        $this->assertStringContainsString('stricterrorchecking', $meta);
        $this->assertStringContainsString('preservewhitespace', $meta);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/dom_option_props.c');
    }
}
