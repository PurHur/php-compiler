<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: null assign to `?Interface` / `?Class` typed properties must match Zend (#36382).
 *
 * TypedPropertyClassAssignCheck used to call emitInstanceOf on TYPE_OBJECT null
 * pointers (nullable ctor params) → SIGSEGV; nullable metadata must skip the check.
 *
 * php-src: Zend/zend_object_handlers.c zend_check_property_type / SEPARATE path.
 *
 * @group llvm
 * @group aot
 */
final class Issue36382NullableIfacePropAotTest extends TestCase
{
    public function testAotNullableIfaceAndClassPropNullAssignMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_nullable_iface_prop.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);
        $this->assertSame(
            ['null_iface', 'empty', 'default_iface', 'empty', 'obj_iface', 'has', 'null_class', 'empty', 'OK'],
            $expected
        );

        $bin = sys_get_temp_dir().'/phpc_36382_nullable_iface_'.getmypid().'.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $env['PHP_COMPILER_LLVM_ASSERT'] = '1';
        $env['PHP_COMPILER_CACHE'] = '0';
        $cmd = [PHP_BINARY, $root.'/bin/compile.php', '-o', $bin, $src];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileRc = proc_close($proc);
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 800));

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $out));
        $this->assertSame($expected, $out);
    }
}
