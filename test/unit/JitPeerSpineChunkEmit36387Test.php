<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * Host compile of JIT peer units that failed NestedJIT / segfault under SPINE_CHUNK
 * before method demote (#36387).
 *
 * @group aot-lint
 */
final class JitPeerSpineChunkEmit36387Test extends TestCase
{
    /**
     * @return list<string>
     */
    public static function failingPeerUnitsProvider(): array
    {
        return [
            ['lib/JIT/Analyzer.php'],
            ['lib/JIT/BasicBlockHelper.php'],
            ['lib/JIT/Builtin/CallArgv.php'],
        ];
    }

    /**
     * @dataProvider failingPeerUnitsProvider
     */
    public function testObjectOnlyEmitSucceedsUnderSpineChunk(string $rel): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$rel;
        $this->assertFileExists($src);

        $tmp = sys_get_temp_dir().'/phpc-jit-36387-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0755, true);
        $entry = $tmp.'/entry.php';
        $out = $tmp.'/unit.o';
        $autoload = $root.'/vendor/autoload.php';
        file_put_contents($entry, "<?php\nrequire_once ".var_export($autoload, true).";\nrequire_once ".var_export($src, true).";\n");

        $cmd = 'PHP_COMPILER_SPINE_CHUNK=1 PHP_COMPILER_KEEP_OBJECT=1 PHP_COMPILER_OBJECT_ONLY=1'
            .' PHP_COMPILER_HELPER_RUNTIME_O=1'
            .' '.escapeshellarg(PHP_BINARY).' -d memory_limit=1536M '
            .escapeshellarg($root.'/bin/compile.php').' -o '.escapeshellarg($out).' '
            .escapeshellarg($entry).' 2>&1';
        exec($cmd, $lines, $rc);
        $this->assertSame(0, $rc, $rel."\n".implode("\n", $lines));
        $this->assertFileExists($out);
        $this->assertGreaterThan(1000, filesize($out));

        @unlink($entry);
        @unlink($out);
        @rmdir($tmp);
    }
}
