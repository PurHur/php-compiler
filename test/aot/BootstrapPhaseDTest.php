<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap AOT link + execute gate for namespaced lib/ units (issue #540 Phase D).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group bootstrap
 */
final class BootstrapPhaseDTest extends TestCase
{
    private static ?bool $llvmReady = null;

    public function testLibOpcodeAotLinkAndExecute(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/bootstrap-aot/lib_opcode/main.php';
        $this->assertFileExists($source);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $root);
        $outDir = $root.'/build/bootstrap-aot-lib';
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            $this->fail('Cannot create '.$outDir);
        }
        $binary = $outDir.'/lib_opcode_main';
        @unlink($binary);

        $compile = [PHP_BINARY, $root.'/bin/compile.php', '-o', $binary, $source];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($compile, $descriptorSpec, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim($stderr !== false ? $stderr : ''));
        $this->assertFileExists($binary);
        $this->assertTrue(is_executable($binary));

        $expected = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($source).' 2>/dev/null');
        $actual = shell_exec(escapeshellarg($binary).' 2>/dev/null');
        $this->assertSame($expected, $actual);
        $this->assertStringContainsString("ok\n", (string) $actual);

        @unlink($binary);
    }

    public function testBootstrapAotLinkLibScript(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg($root.'/script/bootstrap-aot-link-lib.sh').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('target(s) OK', implode("\n", $out));
    }

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));

        return self::$llvmReady;
    }
}
