<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guard #24498: compliance shard membership must be stable under corpus add/remove.
 *
 * Index round-robin (`i % SHARDS`) reshuffled every case sorting after a removed name.
 * Hash membership (`crc32(name) % SHARDS`) moves only the changed case.
 */
final class ShardComplianceHashStableTest extends TestCase
{
    public function testRemovingOneCaseDoesNotReshuffleOthers(): void
    {
        $root = sys_get_temp_dir().'/phpc-shard-hash-'.bin2hex(random_bytes(4));
        $before = $root.'/before';
        $after = $root.'/after';
        mkdir($before.'/stdlib', 0777, true);
        mkdir($after.'/stdlib', 0777, true);

        // Lexicographic order matches the issue's "stdlib/ex*" demonstration slice.
        $names = [
            'stdlib/exec_empty_command_jit',
            'stdlib/exec_null_valueerror',
            'stdlib/explode_trailing',
            'stdlib/extension_loaded_curl_openssl',
            'stdlib/extract_extr_if_exists_uninit_cv',
            'stdlib/extract_extr_prefix_if_exists_new_keys',
            'lang/match_basic',
            'lang/zzz_tail',
        ];
        foreach ($names as $name) {
            $path = $before.'/'.$name.'.phpt';
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, "--TEST--\nstub\n--FILE--\n<?php\n--EXPECT--\n");
        }
        foreach ($names as $name) {
            if ($name === 'stdlib/exec_null_valueerror') {
                continue;
            }
            $path = $after.'/'.$name.'.phpt';
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, "--TEST--\nstub\n--FILE--\n<?php\n--EXPECT--\n");
        }

        $script = dirname(__DIR__, 2).'/script/shard-compliance.sh';
        $shards = 24;
        $stableAcrossRemoval = 0;
        for ($shard = 0; $shard < $shards; $shard++) {
            $beforeMembers = $this->listShard($script, $before, $shards, $shard);
            $afterMembers = $this->listShard($script, $after, $shards, $shard);
            $beforeWithoutRemoved = array_values(array_filter(
                $beforeMembers,
                static fn (string $n): bool => $n !== 'stdlib/exec_null_valueerror'
            ));
            self::assertSame(
                $beforeWithoutRemoved,
                $afterMembers,
                "shard {$shard}: membership of surviving cases must be identical after removal"
            );
            $stableAcrossRemoval += count($beforeWithoutRemoved);
        }
        self::assertSame(count($names) - 1, $stableAcrossRemoval);

        // Index round-robin would have moved explode_trailing out of its pre-removal shard for the
        // issue's demonstrated slice; hash membership keeps every survivor put.
        $explodeShardBefore = $this->shardOf('stdlib/explode_trailing', $shards);
        $explodeAfter = $this->listShard($script, $after, $shards, $explodeShardBefore);
        self::assertContains('stdlib/explode_trailing', $explodeAfter);

        $this->rmTree($root);
    }

    public function testMembershipUsesCrc32OfCaseName(): void
    {
        $name = 'stdlib/explode_trailing';
        $shards = 24;
        $expected = (crc32($name) & 0xffffffff) % $shards;

        $root = sys_get_temp_dir().'/phpc-shard-crc-'.bin2hex(random_bytes(4));
        mkdir($root.'/stdlib', 0777, true);
        file_put_contents(
            $root.'/'.$name.'.phpt',
            "--TEST--\nstub\n--FILE--\n<?php\n--EXPECT--\n"
        );

        $script = dirname(__DIR__, 2).'/script/shard-compliance.sh';
        $members = $this->listShard($script, $root, $shards, $expected);
        self::assertSame([$name], $members);

        $this->rmTree($root);
    }

    /** @return list<string> */
    private function listShard(string $script, string $casesDir, int $shards, int $shard): array
    {
        $cmd = sprintf(
            'COMPLIANCE_CASES_DIR=%s %s --suite=VMTest --shards=%d --shard=%d --list 2>/dev/null',
            escapeshellarg($casesDir),
            escapeshellarg($script),
            $shards,
            $shard
        );
        $lines = [];
        $rc = 0;
        exec($cmd, $lines, $rc);
        $lines = array_values(array_filter(
            $lines,
            static fn (string $l): bool => $l !== '' && $l[0] !== '#'
        ));
        // Tiny fixtures under 24 shards legitimately leave some shards empty (exit 1).
        if ($lines === [] && ($rc === 0 || $rc === 1)) {
            return [];
        }
        self::assertSame(0, $rc, 'shard-compliance --list failed: '.implode("\n", $lines));

        return $lines;
    }

    private function shardOf(string $name, int $shards): int
    {
        return (crc32($name) & 0xffffffff) % $shards;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
