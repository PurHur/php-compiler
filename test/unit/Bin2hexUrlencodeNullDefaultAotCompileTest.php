<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #18912: AOT compile for bin2hex/urlencode/rawurlencode null coercion on default profile. */
final class Bin2hexUrlencodeNullDefaultAotCompileTest extends TestCase
{
    public function testNullCoerceDefaultProfileAotCompileSucceeds(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/fixtures/aot/compile-only/bin2hex_urlencode_null_default.php';
        $out = $repo.'/build/test-bin2hex-urlencode-null-default-aot';
        @mkdir($repo.'/build', 0777, true);
        @unlink($out);

        $cmd = [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $source];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
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
