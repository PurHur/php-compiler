<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * 006-FileUploadWeb README run matrix drift guard (issue #2018).
 */
final class RebuildExamples006RowTest extends TestCase
{
    public function testRebuildExamples006RowCheckerPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-rebuild-examples-006-row.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testRebuildExamples006RowCheckerScriptDocumentsPolicy(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/script/check-rebuild-examples-006-row.php');
        $this->assertNotFalse($script);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_SMOKE_GATE', $script);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_AOT_LINK_GATE', $script);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_AOT_SMOKE_GATE', $script);
        $this->assertStringContainsString('006-FileUploadWeb', $script);
        $this->assertStringContainsString('ci-defaults.env', $script);
        $this->assertStringContainsString('move_uploaded_file', $script);
        $this->assertStringContainsString('#2018', $script);
    }
}
