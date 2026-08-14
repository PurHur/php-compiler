<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: network/DNS builtins excess argc → ArgumentCountError (#30546).
 *
 * php-src: ext/standard/basic_functions.c / network.c / dns.c
 *
 * ACE + later InetRuntime::ensureLinked in the same thin-AOT unit can orphan the
 * insert block ("Current basic block has no parent function", #27088). Guard ACE
 * catchability and happy-path compile in separate binaries.
 *
 * @group llvm
 * @group aot
 */
final class Issue30546NetworkExcessArgcAotTest extends TestCase
{
    public function testAotInetPtonExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    inet_pton('127.0.0.1', 1);
    echo "pton_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'pton_hi ', $e->getMessage(), "\n";
}
try {
    inet_pton();
    echo "pton_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'pton_lo ', $e->getMessage(), "\n";
}
echo "ok_pton_argc\n";
PHP,
            "pton_hi inet_pton() expects exactly 1 argument, 2 given\n"
            ."pton_lo inet_pton() expects exactly 1 argument, 0 given\n"
            ."ok_pton_argc\n"
        );
    }

    public function testAotIp2longExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    ip2long('127.0.0.1', 1);
    echo "ip2long_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ip2long_hi ', $e->getMessage(), "\n";
}
try {
    ip2long();
    echo "ip2long_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ip2long_lo ', $e->getMessage(), "\n";
}
echo "ok_ip2long_argc\n";
PHP,
            "ip2long_hi ip2long() expects exactly 1 argument, 2 given\n"
            ."ip2long_lo ip2long() expects exactly 1 argument, 0 given\n"
            ."ok_ip2long_argc\n"
        );
    }

    public function testAotCheckdnsrrExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    checkdnsrr('example.com', 'A', 1);
    echo "dns_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'dns_hi ', $e->getMessage(), "\n";
}
try {
    checkdnsrr();
    echo "dns_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'dns_lo ', $e->getMessage(), "\n";
}
echo "ok_dns_argc\n";
PHP,
            "dns_hi checkdnsrr() expects at most 2 arguments, 3 given\n"
            ."dns_lo checkdnsrr() expects at least 1 argument, 0 given\n"
            ."ok_dns_argc\n"
        );
    }

    private function assertAotOutput(string $srcCode, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30546_try_'.getmypid().'_'.mt_rand().'.php';
        $bin = sys_get_temp_dir().'/phpc_30546_try_'.getmypid().'_'.mt_rand().'.bin';
        file_put_contents($src, $srcCode);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
