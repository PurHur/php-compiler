<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ReflectionClass getName/getShortName excess argc (#30888).
 *
 * ReflectionFunction::getName and ReflectionClass::getMethod AOT still SEGV on the
 * happy path after ACE — covered by VM/JIT guards instead.
 *
 * @group llvm
 * @group aot
 */
final class Issue30888ReflectionClassExcessArgcAotTest extends TestCase
{
    public function testAotReflectionClassExcessArgc(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30888_aot_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30888_aot_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$r = new ReflectionClass(DateTime::class);
try {
    echo $r->getName(1);
    echo " ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo $r->getShortName(1);
    echo " ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', $r->getName(), ',', $r->getShortName(), "\n";
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
                "ArgumentCountError: ReflectionClass::getName() expects exactly 0 arguments, 1 given\n",
                $text
            );
            $this->assertStringContainsString(
                "ArgumentCountError: ReflectionClass::getShortName() expects exactly 0 arguments, 1 given\n",
                $text
            );
            $this->assertStringContainsString("ok=DateTime,DateTime\n", $text);
            $this->assertStringNotContainsString('ACCEPTED', $text);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
