<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29549 — empty($arr[$object]) TypeError suffix matches isset()/Zend.
 *
 * php-src: Zend/zend_execute.c — ZEND_ISSET_ISEMPTY_DIM_OBJ
 */
final class EmptyObjectArrayOffsetIssetMsgTest extends TestCase
{
    private const EXPECTED =
        "TypeError:Illegal offset type in isset or empty\n"
        ."TypeError:Illegal offset type in isset or empty\n";

    public function testEmptyObjectOffsetMessageMatchesIssetViaRuntime(): void
    {
        $code = file_get_contents(
            dirname(__DIR__).'/repro/empty_object_array_offset_isset_msg_29549.php'
        );
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            $code,
            'empty_object_array_offset_isset_msg_29549.php'
        );
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(self::EXPECTED, $output);
    }

    public function testEmptyObjectOffsetMessageMatchesIssetViaVmBin(): void
    {
        $this->assertBinOutput('bin/vm.php');
    }

    public function testEmptyObjectOffsetMessageMatchesIssetViaJitBin(): void
    {
        $this->assertBinOutput('bin/jit.php');
    }

    private function assertBinOutput(string $binRel): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/empty_object_array_offset_isset_msg_29549.php';
        $cmd = escapeshellarg(PHP_BINARY).' -d zend.exception_ignore_args=0 '
            .escapeshellarg($root.'/'.$binRel).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $joined = implode("\n", $out)."\n";
        // Host may emit vendor deprecations before program output.
        $this->assertStringContainsString(self::EXPECTED, $joined);
    }
}
