<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize() __sleep() non-array → Warning + N; valid sleep lists props only (#13378).
 *
 * @see php-src ext/standard/var.c php_var_serialize_call_sleep
 *
 * @group llvm
 * @group aot
 */
final class SerializeBadSleep13378AotTest extends TestCase
{
    private const REPRO = 'test/repro/serialize_sleep_aot.php';

    public function testVmSerializeSleepMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__, 2).'/'.self::REPRO);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'serialize_sleep_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "O:9:\"GoodSleep\":1:{s:1:\"a\";i:1;}\nN;\n",
            $out
        );
    }

    public function testAotSerializeSleepMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.self::REPRO;
        $bin = sys_get_temp_dir().'/phpc_issue_13378_sleep_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $out = implode("\n", $runOut)."\n";
        // #13378: __sleep must filter props (one slot) and non-array sleep → N;
        $this->assertStringStartsWith('O:9:"GoodSleep":1:{s:1:"a";', $out);
        $this->assertStringNotContainsString('s:1:"b"', $out);
        $this->assertStringEndsWith("N;\n", $out);
    }
}
