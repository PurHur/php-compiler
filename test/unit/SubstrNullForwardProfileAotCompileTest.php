<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile for substr(null) under declare(strict_types=1) — TypeError at runtime (#18980).
 * Non-strict soft-null on PROFILE=8.4 is #24817 / #21189 (not a compile-time TypeError).
 */
final class SubstrNullForwardProfileAotCompileTest extends TestCase
{
    public function testNullHaystackForwardProfileAotCompileSucceeds(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/fixtures/aot/compile-only/substr_null_typeerror.php';
        $out = $repo.'/build/test-substr-null-forward-profile-aot';
        @mkdir($repo.'/build', 0777, true);
        @unlink($out);

        $env = array_merge(getenv(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $cmd = [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $source];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim($stdout."\n".$stderr));
        $this->assertFileExists($out);
    }
}
