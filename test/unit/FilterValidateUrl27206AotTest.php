<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: dynamic filter_var(FILTER_VALIDATE_URL) must not SEGV under helper-runtime cache (#27206).
 *
 * FilterUrlValidate unit.o is runtime-unsafe (peer FilterEmailValidate #27068); consumption
 * falls back to NestedJIT into the user module.
 *
 * @group llvm
 * @group aot
 */
final class FilterValidateUrl27206AotTest extends TestCase
{
    public function testFilterUrlValidateManifestIsRuntimeUnsafe(): void
    {
        $root = dirname(__DIR__, 2);
        $manifestPath = $root.'/prelinked/helper-runtime/x86_64-linux/units/ext_filter_FilterUrlValidate_php/manifest.json';
        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame(false, $manifest['runtime_safe'] ?? null);
    }

    public function testDynamicValidateUrlMatchesZendWithHelperRuntimeCache(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_filter_validate_url.php';
        $bin = sys_get_temp_dir().'/phpc_fvu_27206_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(
                "'https://example.com/a'\nfalse\n'https://example.com/a'\n",
                implode("\n", $runOut)."\n"
            );
        } finally {
            @unlink($bin);
        }
    }
}
