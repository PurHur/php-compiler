<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/JIT: inet_pton/inet_ntop named arg `$ip` (php-src basic_functions.stub.php, #28916).
 *
 * Reflection is VM-covered by InetReflection28916VMTest. Native compile must accept
 * `ip:` and reject legacy `ip_address` / `in_addr` at NamedArgs resolve time.
 *
 * Happy-path *run* of inet_pton under thin AOT currently segfaults on master
 * (test/repro/inet_pton_ntop_aot_27172.php) — out of scope for Reflection stubs;
 * JIT run exercises the named binding end-to-end.
 *
 * @group llvm
 * @group aot
 */
final class Issue28916InetNamedArgAotTest extends TestCase
{
    public function testAotCompileAcceptsNamedIp(): void
    {
        $this->assertAotCompileSucceeds(
            <<<'PHP'
<?php
echo bin2hex(inet_pton(ip: '127.0.0.1')), "\n";
echo inet_ntop(ip: hex2bin('7f000001')), "\n";
PHP
        );
    }

    public function testAotCompileRejectsLegacyIpAddressName(): void
    {
        $this->assertAotCompileFailsWith(
            <<<'PHP'
<?php
inet_pton(ip_address: '127.0.0.1');
PHP,
            'Unknown named parameter $ip_address'
        );
    }

    public function testAotCompileRejectsLegacyInAddrName(): void
    {
        $this->assertAotCompileFailsWith(
            <<<'PHP'
<?php
inet_ntop(in_addr: hex2bin('7f000001'));
PHP,
            'Unknown named parameter $in_addr'
        );
    }

    public function testJitNamedIpMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28916_jit_'.getmypid().'_'.mt_rand().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo bin2hex(inet_pton(ip: '127.0.0.1')), "\n";
echo inet_ntop(ip: hex2bin('7f000001')), "\n";
echo "ok\n";
PHP);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/jit.php').' '
                .escapeshellarg($src).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $filtered = array_values(array_filter(
                $out,
                static fn (string $line): bool => !str_starts_with($line, 'PHP Deprecated:')
            ));
            $this->assertSame(
                "7f000001\n127.0.0.1\nok\n",
                implode("\n", $filtered)."\n"
            );
        } finally {
            @unlink($src);
        }
    }

    private function assertAotCompileSucceeds(string $srcCode): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28916_ok_'.getmypid().'_'.mt_rand().'.php';
        $bin = sys_get_temp_dir().'/phpc_28916_ok_'.getmypid().'_'.mt_rand().'.bin';
        file_put_contents($src, $srcCode);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $joined = implode("\n", $compileOut);
        try {
            $this->assertSame(0, $compileRc, $joined);
            $this->assertFileExists($bin);
            $this->assertStringNotContainsString('Unknown named parameter', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    private function assertAotCompileFailsWith(string $srcCode, string $needle): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28916_bad_'.getmypid().'_'.mt_rand().'.php';
        $bin = sys_get_temp_dir().'/phpc_28916_bad_'.getmypid().'_'.mt_rand().'.bin';
        file_put_contents($src, $srcCode);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $joined = implode("\n", $compileOut);
        try {
            $this->assertStringContainsString($needle, $joined);
            // Fatal Error during compile may still leave exit 0 depending on host PHP;
            // content match is the contract.
            $this->assertFileDoesNotExist($bin);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
