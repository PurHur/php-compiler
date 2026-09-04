<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — clone $this with many registered classes must AOT-compile quickly.
 *
 * @group aot
 */
final class CloneThisManyClasses36382AotTest extends TestCase
{
    public function testAotCloneThisWithManyClasses(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_clone_this_many_classes.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'clone36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        $cmd = sprintf(
            'php -d memory_limit=512M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        $start = microtime(true);
        exec($cmd, $lines, $ec);
        $elapsed = microtime(true) - $start;
        $this->assertSame(0, $ec, implode("\n", $lines));
        $this->assertFileExists($out);
        $this->assertLessThan(
            45.0,
            $elapsed,
            'clone $this with 80 pad classes must not stall (>45s implies unrestricted cloneObject, #36382); took '
            .sprintf('%.1f', $elapsed).'s'
        );
        exec(escapeshellarg($out), $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc);
        $this->assertSame("a|b", trim(implode("\n", $runLines)));
    }
}
