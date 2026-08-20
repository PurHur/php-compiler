<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize() string array keys must carry correct length (#32911).
 *
 * NestedJIT `(string)$Variable` + `$s.''` produced `s:0:"a"`. Fix: `$key->toString()`
 * and quote without NestedJIT concat (peer JsonEncodeNestedJitHelper #27020).
 * Scalar INF/NAN wire uses ZendDoubleStringRuntime fcmp uppercase (#32911).
 *
 * @see php-src ext/standard/var.c php_var_serialize_intern
 *
 * @group llvm
 * @group aot
 */
final class SerializeStringKey32911AotTest extends TestCase
{
    private const EXPECTED = "a:1:{s:1:\"a\";i:1;}\n"
        ."a:2:{s:5:\"hello\";s:5:\"world\";s:1:\"x\";i:2;}\n"
        ."string(6) \"d:INF;\"\n"
        ."string(6) \"d:NAN;\"\n";

    public function testVmSerializeStringKeysAndInfNan(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32911_serialize_string_key_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32911_serialize_string_key_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSerializeStringKeysAndInfNan(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32911_serialize_string_key_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32911_ser_'.getmypid().'.bin';
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
