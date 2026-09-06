<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — nested prop dim with untyped param outer key under AOT.
 *
 * @group llvm
 * @group aot
 */
final class Issue36382NestedPropDimUntypedParamAotTest extends TestCase
{
    public function testUntypedParamNestedPropDimMatchesZend(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_nested_prop_dim_untyped_param.php';
        $this->assertFileExists($src);

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>/dev/null', $zendOut, $zendEc);
        $this->assertSame(0, $zendEc);
        $this->assertSame(
            ['untyped=1', 'handler=hello_id', 'typed=1'],
            array_map('trim', $zendOut)
        );

        if (!LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $out = tempnam(sys_get_temp_dir(), 'npdim36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $cmd = sprintf(
            'php -d memory_limit=512M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        $lines = [];
        exec($cmd, $lines, $ec);
        $this->assertSame(0, $ec, implode("\n", $lines));
        $this->assertFileExists($out);

        $runLines = [];
        exec(escapeshellarg($out).' 2>/dev/null', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame(
            ['untyped=1', 'handler=hello_id', 'typed=1'],
            array_map('trim', $runLines)
        );
    }
}
