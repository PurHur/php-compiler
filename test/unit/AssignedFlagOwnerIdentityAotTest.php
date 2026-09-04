<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Assigned-flag allocas must be keyed by stable LLVM function identity (#36386).
 *
 * PHPLLVM Function_ wrappers are not pointer-stable; spl_object_id($owner) minted a
 * fresh entry alloca per wrapper so markAssigned and undef guards disagreed — false
 * "Undefined variable" on the fannkuch rotate loop after `$i = 0`.
 *
 * @group aot-lint
 */
final class AssignedFlagOwnerIdentityAotTest extends TestCase
{
    public function testRotateLoopAfterArrayInitHasNoUndefWarnings(): void
    {
        $src = <<<'PHP'
        <?php
        function f(int $n): int {
            $perm1 = [];
            for ($i = 0; $i < $n; ++$i) {
                $perm1[$i] = $i;
            }
            $r = 1;
            $p0 = $perm1[0];
            $i = 0;
            while ($i < $r) {
                $j = $i + 1;
                $perm1[$i] = $perm1[$j];
                $i = $j;
            }
            $perm1[$r] = $p0;
            return $i;
        }
        echo f(3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_assigned_flag_owner_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_assigned_flag_owner_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(escapeshellarg($bin), $descriptors, $pipes);
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $runRc = proc_close($proc);
            $this->assertSame(0, $runRc);
            $this->assertSame("1\n", $stdout);
            $this->assertStringNotContainsString(
                'Undefined variable',
                (string) $stderr,
                'assigned $i/$r/$j must not warn (flag owner identity, #36386)'
            );
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testFannkuchScaledMatchesZendWithoutStderr(): void
    {
        $src = file_get_contents(__DIR__.'/../../benchmarks/v2/fannkuch-redux.php');
        $this->assertNotFalse($src);
        // Smaller n keeps the unit gate fast; same rotate / $r pattern as the bench.
        $src = str_replace('fannkuch(8)', 'fannkuch(4)', $src);
        $path = sys_get_temp_dir().'/phpc_fannkuch_undef_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_fannkuch_undef_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>/dev/null';
            exec($zendCmd, $zendOut, $zendRc);
            $this->assertSame(0, $zendRc);
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(escapeshellarg($bin), $descriptors, $pipes);
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $runRc = proc_close($proc);
            $this->assertSame(0, $runRc);
            $this->assertSame(implode("\n", $zendOut)."\n", $stdout);
            $this->assertSame('', (string) $stderr);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
