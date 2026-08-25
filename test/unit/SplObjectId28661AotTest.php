<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: spl_object_id() must return sequential Zend handles, not pointer addresses (#28661).
 *
 * @group llvm
 * @group aot
 */
final class SplObjectId28661AotTest extends TestCase
{
    public function testAotMatchesZendSequentialHandles(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_28661_aot_spl_object_id.php');
    }

    public function testObjectHandleRuntimeWired(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString(
            'ObjectHandleRuntime::emitUserVisibleHandle',
            (string) file_get_contents($root.'/ext/standard/JitGetObjectId.php')
        );
        $this->assertStringContainsString(
            'user_handle',
            (string) file_get_contents($root.'/lib/JIT/Builtin/Type/Object_.php')
        );
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/splid_28661_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
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
