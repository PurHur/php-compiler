<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * String-literal __string__separate must run once at function entry, not per loop iter (#36386).
 *
 * @group aot-lint
 */
final class ImmortalStringLiteralAotTest extends TestCase
{
    public function testFromLiteralHoistsSeparateToFunctionEntry(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Variable.php');
        $fromLiteral = strstr($src, 'public static function fromLiteral');
        $this->assertNotFalse($fromLiteral);
        $case = strstr((string) $fromLiteral, 'case self::TYPE_STRING:');
        $this->assertNotFalse($case);
        $nextCase = strpos((string) $case, 'case self::TYPE_NATIVE_DOUBLE:');
        $this->assertNotFalse($nextCase);
        $stringArm = substr((string) $case, 0, $nextCase);
        $this->assertStringContainsString('emitAtFunctionEntry', $stringArm);
        $this->assertStringContainsString("lookupFunction('__string__separate')", $stringArm);
        $this->assertStringContainsString('KIND_VARIABLE', $stringArm);
    }

    public function testSimplecallLoopBodyAvoidsStringSeparate(): void
    {
        $src = <<<'PHP'
        <?php
        function simplecall(): void {
            for ($i = 0; $i < 3; ++$i) {
                strlen('hallo');
            }
        }
        simplecall();
        echo "Done\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_immortal_str_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_immortal_str_lit_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');
            $fnStart = strpos($ll, 'define void @simplecall()');
            $this->assertNotFalse($fnStart, 'missing @simplecall');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            // Entry may separate once; the loop body must not.
            $loop = strpos($body, 'block_1402:');
            $this->assertNotFalse($loop, 'missing loop header');
            $errArm = strpos($body, 'incdec_res_err');
            $hot = false === $errArm
                ? substr($body, $loop)
                : substr($body, $loop, $errArm - $loop);
            $this->assertStringNotContainsString('__string__separate', $hot);
            $this->assertStringContainsString('__string__separate', substr($body, 0, $loop));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['Done'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testAssignedStringLiteralMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        function f(): void {
            $s = 'hallo';
            echo strlen($s), "\n";
            echo $s, "\n";
        }
        f();
        PHP;
        $path = sys_get_temp_dir().'/phpc_immortal_assign_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_immortal_assign_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['5', 'hallo'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testStrPadSubstrLiteralsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        $x = 3;
        echo str_pad('p', $x + 2, '-'), "\n";
        echo substr('abcdefgh', $x - 2, $x + 1), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_c11_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_c11_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['p----', 'bcde'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
