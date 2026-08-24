<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** AOT header() after body output emits headers-already-sent Warning (#34513, ext/standard/head.c). */
final class HeaderAlreadySentAot34513Test extends TestCase
{
    public function testAotMatchesZendOn34513Repro(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/issue_34513_time_header_trigger_aot.php';
        $this->assertFileExists($repro);

        $cmd = 'cd '.escapeshellarg($root).' && ./script/differential-sweep.sh --aot --dir '
            .escapeshellarg(dirname($repro)).' --quiet 2>&1 | grep issue_34513';
        $out = shell_exec($cmd.' 2>&1');
        $this->assertIsString($out);
        $this->assertStringContainsString('ok      issue_34513_time_header_trigger_aot.php', $out, $out);
    }
}
