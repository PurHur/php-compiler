<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT FastRoute defaults merge via ?? must run (isset-foreach segfaults).
 *
 * @group aot
 */
final class Issue36382FastrouteDefaultsCoalesceAotTest extends TestCase
{
    public function testCoalesceDefaultsMergeRuns(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_fastroute_defaults_isset.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'frdef36382_');
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
        $this->assertSame(
            ['4:RP:DI', '4:FastRoute\\RouteParser\\Std:DI', 'OK'],
            array_map('trim', $runLines)
        );
    }
}
