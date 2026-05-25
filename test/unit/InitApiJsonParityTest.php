<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * init-apijson template parity (issues #2029, #2000).
 */
final class InitApiJsonParityTest extends TestCase
{
    public function testInitApiJsonParityPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg($root.'/script/check-init-apijson-parity.sh').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testTemplateReadmeDocumentsParityScript(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/templates/init-apijson/README.md');
        $this->assertStringContainsString('check-init-apijson-parity.sh', $readme);
        $this->assertStringContainsString('#695', $readme);
    }
}
