<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Multi-file AOT must not restore entry-only aot.bin after a member edit (#36382).
 *
 * php-src analogy: Zend opcache invalidates when any included script's timestamp/hash
 * changes (Zend/zend_file_cache.c) — not only the entry.
 *
 * @group llvm
 */
final class Issue36382ProjectCacheMemberInvalidateAotTest extends TestCase
{
    public function testIncludedMemberEditInvalidatesArtifactRestore(): void
    {
        $repo = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/phpc_36382_pcache_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir.'/lib', 0777, true));
        $cache = $dir.'/cache';
        $this->assertTrue(mkdir($cache, 0777, true));

        $main = $dir.'/main.php';
        $lib = $dir.'/lib/msg.php';
        file_put_contents($main, "<?php\nrequire __DIR__.'/lib/msg.php';\necho msg(), \"\\n\";\n");
        file_put_contents($lib, "<?php\nfunction msg(): string { return \"v1\"; }\n");

        $bin1 = $dir.'/app1';
        $bin2 = $dir.'/app2';
        $env = 'PHP_COMPILER_CACHE_DIR='.escapeshellarg($cache)
            .' PHP_COMPILER_AOT_USER_SCRIPT=1'
            .' PHP_COMPILER_AOT_INCREMENTAL_INCLUDES=1'
            .' PHP_COMPILER_BUILD_TIMING=json';

        $compile = static function (string $out) use ($repo, $main, $env): array {
            $cmd = 'cd '.escapeshellarg($repo)
                .' && '.$env
                .' php bin/compile.php -o '.escapeshellarg($out).' '.escapeshellarg($main).' 2>&1';
            exec($cmd, $lines, $rc);

            return ['rc' => $rc, 'out' => implode("\n", $lines)];
        };

        $c1 = $compile($bin1);
        $this->assertSame(0, $c1['rc'], $c1['out']);
        $this->assertFileExists($bin1);
        exec(escapeshellarg($bin1).' 2>&1', $o1, $r1);
        $this->assertSame(0, $r1, implode("\n", $o1));
        $this->assertSame("v1\n", implode("\n", $o1)."\n");

        file_put_contents($lib, "<?php\nfunction msg(): string { return \"v2\"; }\n");

        $c2 = $compile($bin2);
        $this->assertSame(0, $c2['rc'], $c2['out']);
        // Must not take the entry-only artifact_cache_hit short-circuit after member edit.
        $this->assertStringNotContainsString(
            'runtime_standalone_artifact_cache_hit',
            $c2['out'],
            'stale entry-only aot.bin restore after member edit (#36382)'
        );
        exec(escapeshellarg($bin2).' 2>&1', $o2, $r2);
        $this->assertSame(0, $r2, implode("\n", $o2));
        $this->assertSame(
            "v2\n",
            implode("\n", $o2)."\n",
            'member edit must appear in rebuilt binary; was: '.implode("\n", $o2)
        );

        // Cleanup
        @unlink($bin1);
        @unlink($bin2);
        @unlink($main);
        @unlink($lib);
        @rmdir($dir.'/lib');
        // best-effort cache tree
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($cache);
        @rmdir($dir);
    }
}
