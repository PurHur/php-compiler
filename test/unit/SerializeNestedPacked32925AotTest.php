<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize() nested packed arrays under string keys must not SIGABRT (#32925).
 *
 * Follow-up to #32911: flat keys fixed; NestedJIT `$val->toArray()` on pair values
 * still aborted. Fix: value-foreach packed chunks (peer JsonEncodeNestedJitHelper #27182).
 *
 * @see php-src ext/standard/var.c php_var_serialize_intern
 *
 * @group llvm
 * @group aot
 */
final class SerializeNestedPacked32925AotTest extends TestCase
{
    private const EXPECTED = "a:2:{s:1:\"a\";i:1;s:1:\"b\";a:1:{i:0;i:2;}}\n"
        ."a:1:{s:1:\"b\";a:2:{i:0;i:2;i:1;i:3;}}\n";

    public function testVmSerializeNestedPacked(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32925_serialize_nested_packed_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32925_serialize_nested_packed_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSerializeNestedPacked(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32925_serialize_nested_packed_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32925_ser_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $matched = 0;
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(10, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
