<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #36382: `: ?string` returns must heap-box `__value__*` (not stack alloca).
 *
 * php-src: Zend/zend_execute.c ZEND_RETURN / ZVAL_COPY
 *
 * @group llvm
 * @group aot
 */
final class Issue36382NullableStringReturnAotTest extends TestCase
{
    public function testNullableStringLiteralAndPropReturnMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root . '/test/repro/issue_36382_nullable_string_return.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($src) . ' 2>&1', $expected, $zendEc);
        $this->assertSame(0, $zendEc, implode("\n", $expected));

        $bin = sys_get_temp_dir() . '/phpc_36382_nsr_' . getmypid() . '.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $env['PHP_COMPILER_CACHE'] = '0';
        $env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = sys_get_temp_dir()
            . '/phpc-helper-36382-nsr-' . getmypid();
        $cmd = [
            PHP_BINARY,
            '-d',
            'memory_limit=512M',
            $root . '/bin/compile.php',
            '-o',
            $bin,
            $src,
        ];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileRc = proc_close($proc);
        $this->assertSame(0, $compileRc, 'compile failed: ' . substr((string) $stderr, 0, 800));
        $this->assertFileExists($bin);

        $out = [];
        exec(escapeshellarg($bin) . ' 2>&1', $out, $runEc);
        @unlink($bin);
        $this->assertSame(0, $runEc, implode("\n", $out));
        $this->assertSame(
            array_map('trim', $expected),
            array_map('trim', $out)
        );
    }
}
