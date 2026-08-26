<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: unserialize user/stdClass public props (#35107).
 *
 * @see php-src ext/standard/var_unserializer.c object_common
 *
 * @group llvm
 * @group aot
 */
final class UnserializeUserObjectProps35107AotTest extends TestCase
{
    private const EXPECT = "x=1|lit=42|a=10,b=20|k=7|name=hi,flag=1,n=3\n";

    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/aot_unserialize_user_object_props.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_unserialize_user_object_props.php'));
        $this->assertSame(self::EXPECT, (string) ob_get_clean());
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_unserialize_user_object_props.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35107_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(rtrim(self::EXPECT), implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
