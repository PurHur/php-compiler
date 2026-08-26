<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * VM: str_pad()/mb_str_pad() STR_PAD_* + nested FuncCall must match Zend (#34890 leftover of #17697 / peer #34559).
 *
 * @see php-src ext/standard/string.c PHP_FUNCTION(str_pad)
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_pad)
 */
final class StrPadNestedConstFetch34890Test extends TestCase
{
    public function testStrPadVmMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_34890_str_pad_nested_constfetch.php';
        $this->assertSame($this->runPhp($src), $this->runVm($src));
    }

    public function testMbStrPadVmMatchesExpected(): void
    {
        $src = __DIR__.'/../repro/issue_34890_mb_str_pad_nested_constfetch.php';
        // Host PHP may be <8.3 (no mb_str_pad); expected is Zend 8.3+ / php-src mbstring.c.
        $expected = implode("\n", [
            "mb='a---'",
            "mb_enc='a---'",
            "mb_both='a---'",
            "mb_lit='a---'",
        ]);
        $this->assertSame($expected, $this->runVm($src));
    }

    public function testDeferredInitHelperPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $compiler = (string) file_get_contents($root.'/lib/Compiler.php');
        $this->assertStringContainsString('strPadDeferredInitForNestedCallArg', $compiler);
        $this->assertStringContainsString('#34890', $compiler);
        $this->assertStringContainsString('skipPrependForStrPadNestedCallArg', $compiler);
        $this->assertStringContainsString("'str_pad' !== \$name && 'mb_str_pad' !== \$name", $compiler);
        $this->assertStringContainsString('firstSiblingInlineFuncCallProducerIndex', $compiler);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }
}
