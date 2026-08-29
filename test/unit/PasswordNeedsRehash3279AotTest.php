<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: password_needs_rehash() via PasswordJitHelper thin path (#3279).
 *
 * @group llvm
 * @group aot
 */
final class PasswordNeedsRehash3279AotTest extends TestCase
{
    private const EXPECT = "needs\ndefault_ok\ninvalid_yes\n";

    public function testAotPasswordNeedsRehashMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/fixtures/aot/cases/password_needs_rehash.phpt';
        $this->assertFileExists($src);
        $php = $this->extractPhpFromPhpt($src);
        $tmp = sys_get_temp_dir().'/phpc_pw_nrh_3279_'.getmypid().'.php';
        file_put_contents($tmp, $php);
        $bin = sys_get_temp_dir().'/phpc_pw_nrh_3279_'.getmypid().'.bin';
        try {
            $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($tmp).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($tmp);
            @unlink($bin);
        }
    }

    private function extractPhpFromPhpt(string $path): string
    {
        $raw = (string) file_get_contents($path);
        if (!preg_match('/--FILE--\s*\n(.*)\n--EXPECT--/s', $raw, $m)) {
            throw new \RuntimeException('phpt missing FILE section: '.$path);
        }

        return $m[1];
    }
}
