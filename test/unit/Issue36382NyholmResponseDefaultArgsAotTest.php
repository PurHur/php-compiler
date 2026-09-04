<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * #36382 — optional ctor defaults rematerialize per call site (Nyholm Response shape).
 *
 * @group aot
 */
final class Issue36382NyholmResponseDefaultArgsAotTest extends TestCase
{
    public function testTwoResponseStyleCtorsAotExecutes(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_nyholm_response_two_ctors.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'resp36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        putenv('PHP_COMPILER_LLVM_ASSERT=1');
        $_ENV['PHP_COMPILER_LLVM_ASSERT'] = '1';
        putenv('PHP_COMPILER_CACHE=0');
        $_ENV['PHP_COMPILER_CACHE'] = '0';
        $cmd = sprintf(
            'php -d memory_limit=1024M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        putenv('PHP_COMPILER_LLVM_ASSERT');
        unset($_ENV['PHP_COMPILER_LLVM_ASSERT']);
        putenv('PHP_COMPILER_CACHE');
        unset($_ENV['PHP_COMPILER_CACHE']);
        $joined = implode("\n", $lines);
        $this->assertSame(0, $ec, $joined);
        $this->assertFileExists($out);
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame('200|404', trim(implode("\n", $runLines)));
    }

    public function testModuleVerifyStderrIsNotUserClassBlocked(): void
    {
        $stderr = "Module verification failed in function __hashtable__alloc:\n"
            ."Instruction does not dominate all uses!\n"
            ."  %1 = call %__hashtable__* @__hashtable__alloc()\n"
            ."  call void @Nyholm_Psr7_Response____construct(%__object__* %2, i64 200, %__hashtable__* %1)\n";
        $this->assertFalse(PhpcBuild::isUserClassAotBlocked($stderr));
    }
}
