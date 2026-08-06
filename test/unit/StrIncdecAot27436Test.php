<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT str_increment/str_decrement under default helper-runtime cache (#27436).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_increment) / str_decrement
 *
 * @group llvm
 * @group aot
 */
final class StrIncdecAot27436Test extends TestCase
{
    public function testDefaultHelperCacheAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.4';
        // Force committed prelink tier — local cache can mask a stale unit.o (#27436).
        $localCache = $root.'/build/helper-runtime-cache';
        $localBak = $root.'/build/helper-runtime-cache.bak.'.getmypid();
        if (is_dir($localCache)) {
            rename($localCache, $localBak);
        }
        $src = '/tmp/phpc_str_incdec_27436_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo str_increment('a'), "\n";
echo str_decrement('b'), "\n";
echo str_increment('9'), "\n";
echo str_decrement('10'), "\n";
PHP);
        $bin = '/tmp/phpc_str_incdec_27436_'.getmypid().'.bin';
        try {
            // Default path: compile.php enables HELPER_RUNTIME_O=1 (not O=0).
            $compile = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $expect = "b\na\n10\n9\n";
            // Empty-stdout / heap class — repeat before claiming fixed (#27436 / AGENTS.md).
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $runText = implode("\n", $runOut)."\n";
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.$runText);
                $this->assertSame($expect, $runText, 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
            // compile.php may recreate build/helper-runtime-cache during the run;
            // prefer restoring the original local tier over leaving the temp bak.
            if (is_dir($localBak)) {
                if (is_dir($localCache)) {
                    self::removeTree($localCache);
                }
                rename($localBak, $localCache);
            }
            if (false === $prevProfile || '' === (string) $prevProfile) {
                putenv('PHP_COMPILER_PROFILE=');
                unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
                $_ENV['PHP_COMPILER_PROFILE'] = $prevProfile;
                $_SERVER['PHP_COMPILER_PROFILE'] = $prevProfile;
            }
        }
    }

    public function testUserScriptInlineOnlyListsStrIncdec(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('#27436', $source);
        $this->assertStringContainsString(
            "strincdecjithelper::incrementargv' => true",
            $source
        );
        $this->assertStringContainsString(
            "strincdecjithelper::decrementargv' => true",
            $source
        );
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
