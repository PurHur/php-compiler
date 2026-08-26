<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: createAttributeNS without documentElement → false + warning (#35180 / re-#19200).
 *
 * @group llvm
 * @group aot
 */
final class Issue35180CreateAttributeNsNoRootAotTest extends TestCase
{
    public function testCreateAttributeNsNoRootMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_createAttributeNS_no_root.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);

        $bin = sys_get_temp_dir().'/phpc_35180_'.getmypid().'.bin';
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

        $joined = implode("\n", $out);
        $this->assertStringContainsString('false', $joined);
        $this->assertStringContainsString(
            'DOMDocument::createAttributeNS(): Document Missing Root Element',
            $joined
        );
        $this->assertStringContainsString('DOMAttr', $joined);
        $this->assertStringContainsString('ex:foo', $joined);
        // Strip warning lines for stdout-shaped parity (path/line differ AOT vs Zend).
        $strip = static function (array $lines): string {
            $keep = [];
            foreach ($lines as $line) {
                if (str_contains($line, 'Document Missing Root Element')) {
                    continue;
                }
                if (str_starts_with(ltrim($line), 'PHP Warning:')) {
                    continue;
                }
                $keep[] = $line;
            }

            return implode("\n", $keep);
        };
        $this->assertSame($strip($expected), $strip($out));
    }

    public function testThinAotGuardsDocumentElement(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomAttributeNodeNS.php');
        $this->assertStringContainsString('Document Missing Root Element', $src);
        $this->assertStringContainsString('#35180', $src);
        $this->assertStringContainsString('JitDomDocumentElement::fetch', $src);
    }
}
