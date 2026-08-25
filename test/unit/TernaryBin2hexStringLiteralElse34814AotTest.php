<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ternary string-returning builtins with a string-literal else arm (#34814).
 *
 * Slot collision between FuncCall name literals and the ?: phi Temporary used to
 * rematerialize the function name (or SIGSEGV). Zend/VM already matched.
 *
 * @group llvm
 * @group aot
 */
final class TernaryBin2hexStringLiteralElse34814AotTest extends TestCase
{
    public function testTernaryBin2hexMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34814_ternary_bin2hex_aot.php');
    }

    public function testGetOperandPrefersTemporaryOverLiteral(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/Block.php');
        $this->assertStringContainsString('#34814', $src);
        $this->assertStringContainsString('operand instanceof Temporary', $src);
    }

    public function testBindBlockConstantSkipsLiteralSlotCollision(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString('#34814', $src);
        $this->assertStringContainsString('FuncCall name-literal slot', $src);
    }

    public function testTernaryEchoPhiUsesSharedLvalue(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34814', $src);
        $this->assertStringContainsString('return $branch->getOperand($branchOp->arg2);', $src);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/ao_34814_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
