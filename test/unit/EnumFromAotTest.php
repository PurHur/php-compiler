<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT execute guard for BackedEnum::from() / ::tryFrom() (#24208).
 *
 * @group llvm
 * @group aot
 */
final class EnumFromAotTest extends TestCase
{
    public function testAotBackedEnumFromAndTryFromExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $this->writeScript($root, <<<'PHP'
<?php
enum Suit: string { case Hearts = 'H'; case Spades = 'S'; }
enum Level: int { case Low = 1; case High = 9; }
echo Suit::from('S')->value, "\n";
echo Suit::tryFrom('x') === null ? "NULL\n" : "bad\n";
echo Level::from(9)->name, "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_enum_from_24208_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("S\nNULL\nHigh\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    /**
     * Issue repro: tryFrom + var_export(..., true) must survive thin AOT (≥5 runs) (#26855).
     * Root cause was NestedJIT var_export under thin AOT (peer print_r #24266).
     */
    public function testAotIntBackedTryFromDoesNotSegfault(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $this->writeScript($root, <<<'PHP'
<?php
enum E: int { case A = 1; }
echo E::tryFrom(1)->name, ' ', var_export(E::tryFrom(9), true), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_enum_tryfrom_26855_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("A NULL\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    /**
     * Invalid from() ValueError is catchable under thin AOT (#24219, #27667).
     *
     * Regression: #27518 sameLlvmFunction missed php-llvm LLVMValueRef::equals(), so the
     * after-call pending-throw check dropped the try handler and aborted as uncaught.
     */
    public function testAotBackedEnumFromValueErrorIsCatchable(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $this->writeScript($root, <<<'PHP'
<?php
enum S: string { case A = 'a'; case B = 'b'; }
enum E: int { case A = 1; }
try {
    S::from('zz');
    echo "no throw\n";
} catch (\ValueError $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
try {
    S::from('zz');
} catch (\Throwable $e) {
    echo "throwable\n";
}
try {
    E::from(9);
    echo "NO";
} catch (\Throwable $e) {
    echo get_class($e), "\n";
}
PHP);
        $bin = sys_get_temp_dir().'/phpc_enum_from_ve_24219_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, implode("\n", $runOut));
                $this->assertSame(
                    "caught: \"zz\" is not a valid backing value for enum S\nthrowable\nValueError\n",
                    implode("\n", $runOut)."\n"
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    private function writeScript(string $root, string $code): string
    {
        $dir = $root.'/var';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail('Could not create var/ for enum from AOT probe');
        }
        $path = $dir.'/enum-from-aot-'.getmypid().'.php';
        file_put_contents($path, $code);

        return $path;
    }
}
