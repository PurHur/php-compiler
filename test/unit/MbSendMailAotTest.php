<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_send_mail leftover of #6548 (#35889).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_send_mail)
 *
 * @group llvm
 * @group aot
 * @group mbstring
 */
final class MbSendMailAotTest extends TestCase
{
    public function testAotMatchesZendWhenTransportUnavailable(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_send_mail.php');
    }

    public function testLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_send_mail.php');
        $this->assertStringContainsString('JitStringBuiltinArg::lower', $src);
        $this->assertStringContainsString('JitValueBox::writeBool', $src);
        $this->assertStringNotContainsString(
            'mb_send_mail() is not lowered for JIT/AOT in this compiler build',
            $src
        );
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        // Discard sendmail-missing stderr from popen; AOT returns false without invoking transport.
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>/dev/null';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mbmail_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
