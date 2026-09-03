<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Full-module LLVM bitcode must round-trip (issue #36387).
 *
 * Root cause: `void*` / `getTypeFromString('void')->pointerType(0)` produced
 * pointer-to-void types that LLVMParseBitcode rejects with Invalid type.
 *
 * @group llvm
 * @group aot
 */
final class BitcodeVoidStarRoundTripTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testVoidStarMapsToI8Pointer(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available');
        }

        $rt = new Runtime(Runtime::MODE_AOT);
        $ctx = $rt->loadJitContext();
        $voidp = $ctx->getTypeFromString('void*');
        $i8p = $ctx->getTypeFromString('int8*');
        $this->assertSame('i8*', $voidp->toString());
        $this->assertSame($i8p->toString(), $voidp->toString());
        $voidpp = $ctx->getTypeFromString('void**');
        $this->assertSame('i8**', $voidpp->toString());
    }

    public function testUserModuleBitcodeRoundTrips(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available');
        }

        $cache = sys_get_temp_dir().'/phpc-bc-rt-'.bin2hex(random_bytes(4));
        mkdir($cache, 0775, true);
        putenv('PHP_COMPILER_CACHE_DIR='.$cache);
        $_ENV['PHP_COMPILER_CACHE_DIR'] = $cache;
        putenv('PHP_COMPILER_CACHE=0');
        $_ENV['PHP_COMPILER_CACHE'] = '0';
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';

        try {
            $src = $cache.'/echo.php';
            file_put_contents($src, "<?php echo \"ok\\n\";\n");
            $rt = new Runtime(Runtime::MODE_AOT);
            $code = (string) file_get_contents($src);
            $block = $rt->parseAndCompile($code, $src);
            $rt->jitCompileBlock($block);
            $ctx = $rt->loadJitContext();
            $ir = $ctx->module->printToString();
            $this->assertSame(0, substr_count($ir, 'void*'), 'IR must not contain void* after #36387');

            $bc = $cache.'/module.bc';
            $ctx->module->writeBitcodeToFile($bc);
            $this->assertFileExists($bc);

            $rt2 = new Runtime(Runtime::MODE_AOT);
            $ctx2 = $rt2->loadJitContext();
            $ctx2->replaceModuleFromBitcodeFile($bc);
            $restored = $ctx2->module->printToString();
            $this->assertGreaterThan(0, preg_match_all('/^define /m', $restored));
            $this->assertSame(0, substr_count($restored, 'void*'));
        } finally {
            putenv('PHP_COMPILER_CACHE_DIR');
            unset($_ENV['PHP_COMPILER_CACHE_DIR']);
            putenv('PHP_COMPILER_CACHE');
            unset($_ENV['PHP_COMPILER_CACHE']);
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
            unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT']);
            $this->removeTree($cache);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
