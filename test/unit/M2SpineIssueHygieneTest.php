<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * M2 spine open-issue hygiene guard (issue #1808).
 */
final class M2SpineIssueHygieneTest extends TestCase
{
    public function testM2SpineIssueHygienePassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-m2-spine-issue-hygiene.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
