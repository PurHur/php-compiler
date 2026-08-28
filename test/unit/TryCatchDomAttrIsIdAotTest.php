<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: DOMAttr::isId() after try/catch must reach merge code (re-#25841).
 *
 * Precompiling merge before emitMergeEntryCheck left try_merge_entry unwired when
 * merge lowering linked DOM isId bridges — try body fell through to ret void.
 *
 * @group llvm
 */
final class TryCatchDomAttrIsIdAotTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — try/DOM isId AOT test needs LLVM');
        }
    }

    public function testIsIdAfterEmptyTryMatchesZend(): void
    {
        $src = <<<'PHP'
<?php
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('id', 'x');
$attr = $e->getAttributeNode('id');
try {
    echo "a\n";
} catch (Throwable $ex) {
}
echo var_export($attr->isId(), true), "\n";
PHP;
        $this->assertAotSourceOutput($src, "a\nfalse\n");
    }

    public function testChainedIsIdAfterSetIdAttributeInTryMatchesZend(): void
    {
        $repro = $this->repoRoot.'/test/repro/maintainer_gap_dom_getattributenode_isid_chain_after_setid.php';
        $this->assertFileExists($repro);
        $zend = $this->runVm($repro);
        $this->assertAotFileOutput($repro, $zend);
    }

    private function runVm(string $path): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($this->repoRoot.'/bin/vm.php').' '
            .escapeshellarg($path).' 2>&1';
        $out = shell_exec($cmd);
        $this->assertIsString($out, 'VM run produced no output');

        return $out;
    }

    private function assertAotSourceOutput(string $source, string $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc_try_isid_src_');
        $this->assertNotFalse($path);
        $path .= '.php';
        file_put_contents($path, $source);
        try {
            $this->assertAotFileOutput($path, $expected);
        } finally {
            @unlink($path);
        }
    }

    private function assertAotFileOutput(string $path, string $expected): void
    {
        $out = tempnam(sys_get_temp_dir(), 'phpc_try_isid_aot_');
        $this->assertNotFalse($out);
        $env = $this->llvmEnv();
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/compile.php', '-o', $out, $path],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, 'AOT compile failed: '.$stderr);
        $this->assertFileExists($out);
        $run = proc_open(
            [$out],
            $descriptorSpec,
            $runPipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        $runErr = stream_get_contents($runPipes[2]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runCode = proc_close($run);
        @unlink($out);
        $this->assertSame(0, $runCode, 'AOT binary failed: '.$runErr);
        $this->assertSame($expected, $stdout);
    }

    /** @return array<string, string> */
    private function llvmEnv(): array
    {
        $env = $_ENV;
        foreach (['PATH', 'HOME', 'TMPDIR', 'TMP', 'TEMP', 'PHP_COMPILER_LLVM_PATH', 'PHP_COMPILER_PROFILE'] as $key) {
            $v = getenv($key);
            if (false !== $v && '' !== $v) {
                $env[$key] = $v;
            }
        }

        return $env;
    }
}
