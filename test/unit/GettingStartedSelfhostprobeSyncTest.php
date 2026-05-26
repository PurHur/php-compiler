<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * GETTING-STARTED §6 drift guard (#2230); enabled in ci-fast when gate=1 after #2222.
 */
final class GettingStartedSelfhostprobeSyncTest extends TestCase
{
    public function testCheckerScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/check-getting-started-selfhostprobe-sync.php';
        $this->assertFileExists($script);
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('008-SelfHostProbe', $body);
        $this->assertStringContainsString('north-star2-verify', $body);
    }

    public function testCheckerFailsUntilSectionSixPresenterLands(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'php '.escapeshellarg($root.'/script/check-getting-started-selfhostprobe-sync.php').' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(1, $code, implode("\n", $lines));
        $out = implode("\n", $lines);
        $this->assertStringContainsString('§6', $out);
    }
}
