<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Closure::bindTo + SensitiveParameterValue::getValue excess argc (#30867).
 *
 * WeakReference::create AOT remains pre-broken on master ("Current basic block has no
 * parent function") even for the happy path — covered by VM/JIT guards instead.
 *
 * @group llvm
 * @group aot
 */
final class Issue30867ClosureSpvExcessArgcAotTest extends TestCase
{
    public function testAotBindToAndSpvExcessArgc(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30867_aot_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30867_aot_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$f = function () { return 1; };
try {
    echo ($f->bindTo(null, 'static', 1))();
    echo " ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$v = new SensitiveParameterValue('secret');
try {
    echo $v->getValue(1);
    echo " ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok_bind=', ($f->bindTo(null, 'static'))(), "\n";
echo 'ok_spv=', $v->getValue(), "\n";
PHP);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $text = implode("\n", $runOut)."\n";
            $this->assertStringContainsString(
                "ArgumentCountError: Closure::bindTo() expects at most 2 arguments, 3 given\n",
                $text
            );
            $this->assertStringContainsString(
                "ArgumentCountError: SensitiveParameterValue::getValue() expects exactly 0 arguments, 1 given\n",
                $text
            );
            $this->assertStringContainsString("ok_bind=1\n", $text);
            $this->assertStringContainsString("ok_spv=secret\n", $text);
            $this->assertStringNotContainsString('ACCEPTED', $text);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
