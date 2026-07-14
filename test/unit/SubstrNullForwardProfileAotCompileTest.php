<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #18980: AOT compile for substr(null) TypeError guard on 8.4 forward profile. */
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
