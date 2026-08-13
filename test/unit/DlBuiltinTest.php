<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** dl() VM/JIT/AOT smoke (issue #3591/#30250, ext/standard/dl.c). */
final class DlBuiltinTest extends TestCase
{
    public function testVmTypeErrorForNonString(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
try {
    dl([]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
PHP,
            'dl_typeerror.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        $this->assertSame(
            "TypeError: dl(): Argument #1 (\$extension_filename) must be of type string, array given\n",
            ob_get_clean()
        );
    }

    /** @see https://github.com/PurHur/php-compiler/issues/30250 */
    public function testVmStrictNullTypeErrorBeforeEnablementWarning(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
declare(strict_types=1);
try {
    dl(null);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
PHP,
            'dl_strict_null.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        $this->assertSame(
            "TypeError: dl(): Argument #1 (\$extension_filename) must be of type string, null given\n",
            ob_get_clean()
        );
    }

    /** Soft-null outside strict_types: DEP then enable_dl Warning + false (#30250). */
    public function testVmNonStrictNullDepThenEnablementWarning(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo ($errno === E_DEPRECATED ? 'DEP' : ($errno === E_WARNING ? 'WARN' : (string) $errno)), ': ', $errstr, "\n";
    return true;
});
try {
    $r = dl(null);
    echo 'result=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
PHP,
            'dl_nonstrict_null.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        $out = (string) ob_get_clean();
        $this->assertStringContainsString(
            'DEP: dl(): Passing null to parameter #1 ($extension_filename) of type string is deprecated',
            $out
        );
        $this->assertStringContainsString("WARN: Dynamically loaded extensions aren't enabled", $out);
        $this->assertStringContainsString('result=false', $out);
        $this->assertStringNotContainsString('TypeError', $out);
    }

    /**
     * @group llvm
     */
    public function testAotCompileOnlyLowering(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $target = dirname(__DIR__, 2).'/test/fixtures/aot/compile-only/dl_stub.php';
        $this->assertFileExists($target);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile((string) file_get_contents($target), 'dl_stub_jit_compile.php');
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    /**
     * @group llvm
     * @see https://github.com/PurHur/php-compiler/issues/30250
     */
    public function testAotStrictNullTypeError(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_30250_dl_null_strict.php';
        $out = sys_get_temp_dir().'/dl_strict_null_'.getmypid();
        $cmd = sprintf(
            'cd %s && ./phpc build -o %s %s 2>/dev/null && %s',
            escapeshellarg($repo),
            escapeshellarg($out),
            escapeshellarg($src),
            escapeshellarg($out)
        );
        exec($cmd, $lines, $code);
        @unlink($out);
        $this->assertSame(0, $code, implode("\n", $lines));
        $this->assertSame(
            ['TypeError: dl(): Argument #1 ($extension_filename) must be of type string, null given'],
            $lines
        );
    }
}
