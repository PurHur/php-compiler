<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT match($this) inside a backed enum method (#24163, #24388).
 *
 * @group llvm
 * @group aot
 */
final class MatchEnumThisAotTest extends TestCase
{
    public function testAotBackedEnumMatchThisPrintsColor(): void
    {
        $this->assertAotPrints(
            dirname(__DIR__, 2).'/test/repro/issue_24163_enum_match_this.php',
            "H black\n"
        );
    }

    /** Original issue shape: concat + Enum::from() + match($this) color(). */
    public function testAotBackedEnumFromThenMatchThis(): void
    {
        $this->assertAotPrints(
            dirname(__DIR__, 2).'/test/differential/cases/m04_enum_match_this.php',
            "H black\n"
        );
    }

    /**
     * Cold helper-runtime cache must still compile — #24388 PHI predecessor bug was
     * masked when a warm cache skipped the UnhandledMatchError string-quote concat path.
     */
    public function testAotBackedEnumMatchThisWithColdHelperCache(): void
    {
        $cache = sys_get_temp_dir().'/phpc_hrc_24388_'.getmypid();
        if (!mkdir($cache) && !is_dir($cache)) {
            $this->fail('could not create cold helper cache dir');
        }
        $prevCache = getenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR');
        $prevO = getenv('PHP_COMPILER_HELPER_RUNTIME_O');
        putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.$cache);
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        try {
            $this->assertAotPrints(
                dirname(__DIR__, 2).'/test/differential/cases/m04_enum_match_this.php',
                "H black\n"
            );
            $this->assertAotPrints(
                dirname(__DIR__, 2).'/test/differential/cases/k06_enum_backed_match.php',
                "H black\n"
            );
        } finally {
            if (false === $prevCache) {
                putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR');
            } else {
                putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.$prevCache);
            }
            if (false === $prevO) {
                putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            } else {
                putenv('PHP_COMPILER_HELPER_RUNTIME_O='.$prevO);
            }
            foreach (glob($cache.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($cache);
        }
    }

    private function assertAotPrints(string $src, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_match_24163_'.getmypid().'_'.md5($src).'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expected, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
