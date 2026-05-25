<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Root README.md drift guard (issue #1832).
 */
final class RootReadmeSyncTest extends TestCase
{
    public function testRootReadmeSyncScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/check-root-readme-sync.php';
        $this->assertFileExists($script);
    }

    /**
     * Master README still carries pre-#1525 north-star wording; guard must detect it.
     */
    public function testRootReadmeSyncDetectsStaleNorthStarWordingOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-root-readme-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(1, $code, 'expected failure until #1525 fixes README.md');
        $joined = implode("\n", $out);
        $this->assertStringContainsString('check-root-readme-sync:', $joined);
        $this->assertMatchesRegularExpression('/README\.md:\d+:/', $joined);
    }
}
