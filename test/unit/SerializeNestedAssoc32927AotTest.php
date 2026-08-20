<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize() nested assoc string keys must not renumber to i:0 (#32927).
 *
 * Follow-up to #32925 value-foreach: use key=>value foreach so NestedJIT keeps
 * string keys without $val->toArray() (SIGABRT on pair values).
 *
 * @see php-src ext/standard/var.c php_var_serialize_intern
 *
 * @group llvm
 * @group aot
 */
final class SerializeNestedAssoc32927AotTest extends TestCase
{
    private const EXPECTED = "a:1:{s:1:\"c\";a:1:{s:1:\"d\";i:3;}}\n"
        ."a:2:{s:1:\"a\";i:1;s:1:\"b\";a:1:{i:0;i:2;}}\n"
        ."a:1:{s:5:\"outer\";a:2:{s:1:\"x\";s:1:\"y\";s:1:\"n\";i:7;}}\n";

    public function testVmSerializeNestedAssocKeys(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32927_serialize_nested_assoc_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32927_serialize_nested_assoc_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSerializeNestedAssocKeys(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32927_serialize_nested_assoc_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32927_ser_'.getmypid().'.bin';
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
