<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * init-sessionsweb template parity (issues #1902, #1886).
 */
final class InitSessionswebParityTest extends TestCase
{
    public function testInitSessionswebParityPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg($root.'/script/check-init-sessionsweb-parity.sh').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testTemplateReadmeDocumentsParityScript(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/templates/init-sessionsweb/README.md');
        $this->assertStringContainsString('check-init-sessionsweb-parity.sh', $readme);
        $this->assertStringContainsString('#695', $readme);
    }
}
