<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: session.use_cookies / use_only_cookies / save_handler ini_get defaults (#33059 leaf).
 *
 * @see php-src ext/session/session.c / ext/standard/ini.c
 *
 * @group aot-lint
 */
final class SessionIniDefaultsAotTest extends TestCase
{
    /**
     * @group llvm
     * @group aot
     */
    public function testAotSessionIniDefaultsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_session_ini_defaults.php';
        $bin = sys_get_temp_dir().'/phpc_session_ini_'.getmypid().'.bin';
        $outFile = sys_get_temp_dir().'/phpc_session_ini_'.getmypid().'.out';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            exec(escapeshellarg($bin).' >'.escapeshellarg($outFile).' 2>&1', $ignored, $runRc);
            $stdout = (string) file_get_contents($outFile);
            $this->assertSame(0, $runRc, $stdout);
            $this->assertSame("ok\n", $stdout);
        } finally {
            @unlink($bin);
            @unlink($outFile);
        }
    }
}
