<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode(mb_str_split(lit), JSON_UNESCAPED_UNICODE) must match Zend (#34323 / re-#27242).
 *
 * Hoisted JSON_* ConstFetch emits json_encode INIT before the nested mb_str_split INIT.
 * {main} must save the outer callee, not wipe it (#23472 leftover).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_split)
 * @see php-src ext/json/json.c PHP_FUNCTION(json_encode)
 *
 * @group llvm
 * @group aot
 */
final class MbStrSplitJsonEncode34323AotTest extends TestCase
{
    public function testAotNestedMultibyteWithUnicodeFlagMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_str_split_json_encode.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testMainScriptNestedInitPreservesOuterCallee(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('isMainScript() && null === $this->context->scope->toCall', $src);
        $this->assertStringContainsString('#27242', $src);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_json_'.getmypid().'_'.md5($src);
        $cache = sys_get_temp_dir().'/mb_json_hr_'.getmypid();
        @mkdir($cache, 0777, true);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
