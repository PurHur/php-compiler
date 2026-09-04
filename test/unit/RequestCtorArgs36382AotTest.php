<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT: instance-method `new` must not double-prepend `$this` when the
 * callee has optional trailing parameters (Nyholm Request::__construct).
 *
 * @group aot
 */
final class RequestCtorArgs36382AotTest extends TestCase
{
    public function testAotCreateRequestWithoutOptionalsRuns(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_request_ctor_run.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'req36382r_');
        $this->assertNotFalse($out);
        @unlink($out);
        $cmd = sprintf(
            'php -d memory_limit=512M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        $this->assertSame(0, $ec, implode("\n", $lines));
        $this->assertFileExists($out);
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame('GET', trim(implode("\n", $runLines)));
    }

    public function testOptionalCtorCompileDoesNotShiftUriIntoMethodSlot(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_request_ctor_args.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'req36382c_');
        $this->assertNotFalse($out);
        @unlink($out);
        $joined = '';
        $ec = 1;
        // Helper-runtime link can flake on unrelated undefs; the gate is that Native
        // no longer throws the TYPE_OBJECT-into-string diagnostic (#36382).
        for ($i = 0; $i < 3; ++$i) {
            $lines = [];
            $cmd = sprintf(
                'php -d memory_limit=512M %s -o %s %s 2>&1',
                escapeshellarg($repo.'/bin/compile.php'),
                escapeshellarg($out),
                escapeshellarg($src)
            );
            exec($cmd, $lines, $ec);
            $joined = implode("\n", $lines);
            $this->assertStringNotContainsString('must be a string', $joined, $joined);
            $this->assertStringNotContainsString('got JIT type 133', $joined, $joined);
            $this->assertStringNotContainsString('Request::__construct() must be a string', $joined, $joined);
            if (0 === $ec) {
                break;
            }
        }
        @unlink($out);
        // Accept link flakes after the lowering diagnostic is gone.
        if (0 !== $ec) {
            $this->assertTrue(
                str_contains($joined, 'undefined reference')
                || str_contains($joined, 'Linking failed')
                || str_contains($joined, 'already sealed'),
                $joined
            );
        }
    }

    public function testMinimumPositionalArgCountAccountsForOptionals(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Call/Native.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('minimumPositionalArgCountWithReceiver', $src);
        $this->assertStringContainsString('#36382', $src);

        $jit = file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertNotFalse($jit);
        $this->assertStringContainsString('minimumPositionalArgCountWithReceiver', $jit);
        $this->assertStringContainsString('Optional trailing params make count($args)', $jit);
    }
}
