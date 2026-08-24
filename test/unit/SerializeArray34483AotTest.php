<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize() non-empty arrays must not SIGABRT (#34483).
 *
 * NestedJIT SerializeNestedJitHelper::encodeHashtable aborted on flat/assoc HTs;
 * fix: SerializeArrayLlvm (peer JsonEncodeArrayLlvm) + JIT-bool-before-float in
 * StringSerialize value bridge (NATIVE_BOOL=2 ↔ VM FLOAT=2).
 *
 * @see php-src ext/standard/var.c php_var_serialize
 *
 * @group llvm
 * @group aot
 */
final class SerializeArray34483AotTest extends TestCase
{
    private const EXPECTED = "a:0:{}\n"
        ."a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}\n"
        ."a:3:{s:2:\"ok\";b:1;s:1:\"n\";i:1;s:3:\"msg\";s:2:\"hi\";}\n"
        ."a:1:{i:0;a:2:{i:0;i:1;i:1;i:2;}}\n";

    public function testVmSerializeArraysMatchZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34483_serialize_array_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34483_serialize_array_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSerializeArraysNoAbort(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34483_serialize_array_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34483_ser_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $matched = 0;
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(5, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
