<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT: untyped declared prop without initializer → null (not Undefined property).
 */
final class Issue36382UntypedPropNoDefaultAotTest extends TestCase
{
    public function testAotUntypedPropNoDefaultIsNull(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_untyped_prop_no_default.php';
        $bin = sys_get_temp_dir().'/phpc-36382-untyped-port-'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $out, $code);
        $this->assertSame(0, $code, "compile failed:\n".implode("\n", $out));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
            $text = implode("\n", $runOut);
            $this->assertSame(0, $runCode, "run failed:\n".$text);
            $this->assertStringNotContainsString('Undefined property', $text);
            $this->assertSame("NULL\n80\nNULL\nok", $text);
        } finally {
            @unlink($bin);
        }
    }
}
