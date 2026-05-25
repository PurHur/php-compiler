<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * MiniWebApp zero-unsupported lint guard (issue #2078).
 */
final class MiniWebAppLintZeroTest extends TestCase
{
    public function testMiniWebAppLintZeroPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-miniwebapp-lint-zero.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
