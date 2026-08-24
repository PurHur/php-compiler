<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize() user objects with props must not SIGABRT (#34493).
 *
 * NestedJIT SerializeObjectNestedJitHelper::encodeObjectProps aborted on non-empty
 * property HTs; fix: SerializeObjectPropsLlvm (peer SerializeArrayLlvm #34483).
 *
 * @see php-src ext/standard/var.c php_var_serialize
 *
 * @group llvm
 * @group aot
 */
final class SerializeObject34493AotTest extends TestCase
{
    private const EXPECTED = "O:8:\"stdClass\":0:{}\n"
        ."O:1:\"O\":1:{s:1:\"x\";i:1;}\n"
        ."O:1:\"M\":2:{s:1:\"x\";i:1;s:1:\"y\";s:2:\"hi\";}\n"
        ."O:1:\"N\":1:{s:1:\"a\";a:2:{i:0;i:1;i:1;i:2;}}\n";

    public function testVmSerializeObjectsMatchZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34493_serialize_object_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34493_serialize_object_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSerializeObjectsNoAbort(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34493_serialize_object_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34493_ser_'.getmypid().'.bin';
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
