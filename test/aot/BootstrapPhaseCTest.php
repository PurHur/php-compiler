<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap AOT link + execute gate (issue #512 Phase C).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group bootstrap
 */
final class BootstrapPhaseCTest extends TestCase
{
    private static ?bool $llvmReady = null;

    public function testEchoHelloAotLinkAndExecute(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/bootstrap-aot/echo_hello.php';
        $this->assertFileExists($source);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $root);
        $outDir = $root.'/build/bootstrap-aot';
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            $this->fail('Cannot create '.$outDir);
        }
        $binary = $outDir.'/echo_hello';
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
        $this->assertStringContainsString('Hello Bootstrap', (string) $actual);

        @unlink($binary);
    }

    public function testMinimalClassAotLinkAndExecute(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/bootstrap-aot/minimal_class.php';
        $this->assertFileExists($source);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $root);
        $outDir = $root.'/build/bootstrap-aot';
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            $this->fail('Cannot create '.$outDir);
        }
        $binary = $outDir.'/minimal_class';
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
        $this->assertStringContainsString('hi', (string) $actual);

        @unlink($binary);
    }

    public function testStdlibStringTrimAotLinkAndExecute(): void
    {
        $this->assertBootstrapFixtureLinkAndExecute('stdlib_string_trim.php', '1');
    }

    public function testStdlibStringUrlencodeAotLinkAndExecute(): void
    {
        $this->assertBootstrapFixtureLinkAndExecute('stdlib_string_urlencode.php', '1');
    }

    public function testStdlibFilesystemAotLinkAndExecute(): void
    {
        $this->assertBootstrapFixtureLinkAndExecute('stdlib_filesystem.php', '11');
    }

    public function testStdlibStreamAotLinkAndExecute(): void
    {
        $this->assertBootstrapFixtureLinkAndExecute('stdlib_stream.php', '1');
    }

    public function testStdlibArrayOpsAotLinkAndExecute(): void
    {
        $this->assertBootstrapFixtureLinkAndExecute('stdlib_array_ops.php', '42671');
    }

    public function testStdlibArrayUnshiftAotLinkAndExecute(): void
    {
        $this->assertBootstrapFixtureLinkAndExecute('stdlib_array_unshift.php', '1233');
    }

    public function testM3GetenvSmokeAotLinkAndExecute(): void
    {
        $this->assertBootstrapFixtureLinkAndExecute('m3_getenv_smoke.php', '1');
    }

    public function testM3PreludeSmokeAotLinkAndExecute(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/bootstrap-aot/m3_prelude_smoke.php';
        $this->assertFileExists($source);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_M3_SOURCE'] = $root.'/examples/000-HelloWorld/example.php';
        LlvmToolchain::applyProcessEnv($env, $root);
        $outDir = $root.'/build/bootstrap-aot';
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            $this->fail('Cannot create '.$outDir);
        }
        $binary = $outDir.'/m3_prelude_smoke';
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

        $expected = shell_exec(
            'PHP_COMPILER_M3_SOURCE='.escapeshellarg($env['PHP_COMPILER_M3_SOURCE']).' '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($source).' 2>/dev/null'
        );
        $actual = shell_exec(
            'PHP_COMPILER_M3_SOURCE='.escapeshellarg($env['PHP_COMPILER_M3_SOURCE']).' '
            .escapeshellarg($binary).' 2>/dev/null'
        );
        $this->assertSame($expected, $actual);
        $this->assertStringContainsString('ok-', (string) $actual);

        @unlink($binary);
    }

    public function testBootstrapAotLinkScript(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg($root.'/script/bootstrap-aot-link.sh').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('target(s) OK', implode("\n", $out));
    }

    private function assertBootstrapFixtureLinkAndExecute(string $fixture, string $contains): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/bootstrap-aot/'.$fixture;
        $this->assertFileExists($source);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $root);
        $outDir = $root.'/build/bootstrap-aot';
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            $this->fail('Cannot create '.$outDir);
        }
        $base = pathinfo($fixture, PATHINFO_FILENAME);
        $binary = $outDir.'/'.$base;
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
        $this->assertStringContainsString($contains, (string) $actual);

        @unlink($binary);
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
