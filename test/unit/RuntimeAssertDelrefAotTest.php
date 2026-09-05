<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Config;
use PHPCompiler\JIT\Builtin\Refcount;
use PHPUnit\Framework\TestCase;

/**
 * PHPC_RUNTIME_ASSERT=1 emits M1 (rc < 0 on delref) / M5 (exclusive write) and inject probes abort (#36397).
 *
 * @group aot-lint
 */
final class RuntimeAssertDelrefAotTest extends TestCase
{
    public function testSpecDocumentsNumberedMemoryInvariants(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/runtime-semantics.md');
        $this->assertStringContainsString('## Memory model (#36397)', $doc);
        $this->assertStringContainsString('**M1**', $doc);
        $this->assertStringContainsString('**M5**', $doc);
        $this->assertStringContainsString('__ref__assert_exclusive', $doc);
        $this->assertStringContainsString('__hashtable__grow', $doc);
        $this->assertStringContainsString('lookupStringKeyForWriteBranch', $doc);
        $this->assertStringContainsString('zend_gc.c', $doc);
        $this->assertStringContainsString('zend_variables.h', $doc);
        $this->assertStringContainsString('PHP_COMPILER_RUNTIME_ASSERT', $doc);
        $this->assertStringContainsString('asan-smoke.sh', $doc);
        $this->assertStringContainsString('mutate-assert-smoke.sh', $doc);
        $this->assertStringContainsString('differential-soak.sh', $doc);
        $this->assertStringContainsString('never raw `/opt/llvm9/ld`', $doc);
    }

    public function testAsanLinkSkipsRawLdDriver(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/AOT/Linker.php');
        $this->assertStringContainsString('sanitizerRequested', $src);
        $this->assertStringContainsString('Prefer clang/gcc (#36397)', $src);
        $this->assertMatchesRegularExpression(
            '/if \\(self::sanitizerRequested\\(\\)\\) \\{[^}]*linkWithSystemCompiler/s',
            $src
        );
        $this->assertFileExists(dirname(__DIR__, 2).'/script/runtime-assert/asan-smoke.sh');
        $this->assertFileExists(dirname(__DIR__, 2).'/script/runtime-assert/valgrind-smoke.sh');
        $this->assertFileExists(dirname(__DIR__, 2).'/script/runtime-assert/mutate-assert-smoke.sh');
        $this->assertFileExists(dirname(__DIR__, 2).'/script/runtime-assert/differential-soak.sh');
        $this->assertFileExists(dirname(__DIR__, 2).'/test/runtime-assert/STREAK.json');
        $this->assertFileExists(dirname(__DIR__, 2).'/test/runtime-assert/soak/COUNT');
    }

    public function testAsanSmokeResolvesRepoRootTwoLevelsUp(): void
    {
        $asan = (string) file_get_contents(dirname(__DIR__, 2).'/script/runtime-assert/asan-smoke.sh');
        $vg = (string) file_get_contents(dirname(__DIR__, 2).'/script/runtime-assert/valgrind-smoke.sh');
        $soak = (string) file_get_contents(dirname(__DIR__, 2).'/script/runtime-assert/differential-soak.sh');
        $mutate = (string) file_get_contents(dirname(__DIR__, 2).'/script/runtime-assert/mutate-assert-smoke.sh');
        // #36719 moved smokes under script/runtime-assert/; dirname/.. is script/ and breaks.
        $this->assertMatchesRegularExpression(
            '#ROOT="\$\(cd "\$\(dirname "\$0"\)/\.\./\.\." && pwd\)"#',
            $asan
        );
        $this->assertMatchesRegularExpression(
            '#ROOT="\$\(cd "\$\(dirname "\$0"\)/\.\./\.\." && pwd\)"#',
            $vg
        );
        $this->assertMatchesRegularExpression(
            '#ROOT="\$\(cd "\$\(dirname "\$0"\)/\.\./\.\." && pwd\)"#',
            $soak
        );
        $this->assertMatchesRegularExpression(
            '#ROOT="\$\(cd "\$\(dirname "\$0"\)/\.\./\.\." && pwd\)"#',
            $mutate
        );
        $this->assertStringContainsString('bin/compile.php', $asan);
        $this->assertStringContainsString('missing bin/compile.php', $asan);
        $this->assertStringContainsString('Do not wrap ASan binaries in GNU timeout', $asan);
        $this->assertStringContainsString('RUNTIME_ASSERT_ASAN_FULL', $asan);
        $this->assertStringContainsString('mode=${mode}', $asan);
        $this->assertStringContainsString('SKIP_NO_VALGRIND', $vg);
        $this->assertStringContainsString('expect_n', $vg);
        $this->assertStringContainsString('refcount_cow_churn_36397', $soak);
        $this->assertStringContainsString('PHP_COMPILER_ASAN=1', $soak);
        $this->assertStringContainsString('__hashtable__grow', $mutate);
        $this->assertStringContainsString('__ref__assert_exclusive', $mutate);
        $this->assertStringContainsString('INJECT_SHARED_WRITE', $mutate);
    }

    public function testStreakLedgerRefusesEmptyPass(): void
    {
        $streak = (string) file_get_contents(dirname(__DIR__, 2).'/script/runtime-assert/streak.sh');
        $ledger = (string) file_get_contents(dirname(__DIR__, 2).'/test/runtime-assert/STREAK.json');
        $this->assertStringContainsString('dual_consecutive', $streak);
        $this->assertStringContainsString('SKIP_NO_VALGRIND', $streak);
        $this->assertStringContainsString('empty intersection is not a pass', $streak);
        $this->assertStringContainsString('STREAK_SKIP_RUN', $streak);
        $this->assertStringContainsString('"asan_ok_days": []', $ledger);
        $this->assertStringContainsString('empty ≠ pass', $ledger);
        $case = dirname(__DIR__, 2).'/test/differential/cases/refcount_cow_churn_36397.php';
        $this->assertFileExists($case);
        $src = (string) file_get_contents($case);
        $this->assertStringContainsString('@differential-repeat: 10', $src);
        $this->assertStringContainsString('#36397', $src);
    }

    public function testRefcountPhpEmitsM1AndM5Guards(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/Refcount.php');
        $ht = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('emitRuntimeAssertDelrefUnderflow', $src);
        $this->assertStringContainsString('implementRuntimeAssertExclusive', $src);
        $this->assertStringContainsString('emitAssertExclusiveCall', $src);
        $this->assertStringContainsString('__ref__assert_exclusive', $src);
        $this->assertStringContainsString('phpc_runtime_assert_fail', $src);
        $this->assertStringContainsString('phpc_runtime_assert_inject_shared_write', $src);
        $this->assertStringContainsString('PHPC_RUNTIME_ASSERT M%d', $src);
        $this->assertStringContainsString('separate before write to shared container', $src);
        $this->assertStringContainsString('INT_SLT', $src);
        $this->assertStringContainsString('runtime_assert_m1_unclaimed', $src);
        $this->assertStringContainsString('rc < 0 on delref', $src);
        $this->assertStringContainsString('INT_SGT', $src);
        $this->assertStringContainsString('Refcount::emitAssertExclusiveCall', $ht);
        $this->assertStringContainsString('Packed index writes all go through grow', $ht);
        $this->assertStringContainsString('String-key mutators share this chokepoint', $ht);
    }

    public function testEnabledReadsAliasAndConfigKnob(): void
    {
        $prev = [
            getenv('PHP_COMPILER_RUNTIME_ASSERT'),
            getenv('PHPC_RUNTIME_ASSERT'),
            getenv('PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE'),
        ];
        try {
            putenv('PHP_COMPILER_RUNTIME_ASSERT');
            putenv('PHPC_RUNTIME_ASSERT');
            putenv('PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE');
            unset(
                $_ENV['PHP_COMPILER_RUNTIME_ASSERT'],
                $_SERVER['PHP_COMPILER_RUNTIME_ASSERT'],
                $_ENV['PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE'],
                $_SERVER['PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE']
            );
            $this->assertFalse(Refcount::runtimeAssertEnabled());
            putenv('PHPC_RUNTIME_ASSERT=1');
            $this->assertTrue(Refcount::runtimeAssertEnabled());
            putenv('PHPC_RUNTIME_ASSERT');
            putenv('PHP_COMPILER_RUNTIME_ASSERT=1');
            $this->assertTrue(Refcount::runtimeAssertEnabled());
            $this->assertFalse(Refcount::runtimeAssertInjectSharedWriteEnabled());
            putenv('PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE=1');
            $this->assertTrue(Refcount::runtimeAssertInjectSharedWriteEnabled());
            $this->assertArrayHasKey('PHP_COMPILER_RUNTIME_ASSERT', Config::registry());
            $this->assertArrayHasKey('PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE', Config::registry());
            $this->assertArrayHasKey('PHP_COMPILER_ASAN', Config::registry());
        } finally {
            foreach ([
                'PHP_COMPILER_RUNTIME_ASSERT' => $prev[0],
                'PHPC_RUNTIME_ASSERT' => $prev[1],
                'PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE' => $prev[2],
            ] as $name => $val) {
                if (false === $val) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv($name.'='.$val);
                    $_ENV[$name] = $val;
                }
            }
        }
    }

    public function testInjectedDoubleDelrefAbortsWithM1(): void
    {
        $marker = 'should-not-print-m1-'.bin2hex(random_bytes(4));
        $src = "<?php\necho \"{$marker}\\n\";\n";
        $suffix = getmypid().'_'.bin2hex(random_bytes(4));
        $path = sys_get_temp_dir().'/phpc_runtime_assert_m1_'.$suffix.'.php';
        $bin = sys_get_temp_dir().'/phpc_runtime_assert_m1_'.$suffix.'.bin';
        file_put_contents($path, $src);
        @unlink('/tmp/phpc-last.ll');
        $env = 'PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg(sys_get_temp_dir().'/phpc-assert-cache-m1-'.$suffix)
            .' PHP_COMPILER_RUNTIME_ASSERT=1 PHP_COMPILER_RUNTIME_ASSERT_INJECT_DOUBLE_DELREF=1 PHP_COMPILER_DUMP_IR=1';
        try {
            $cmd = $env.' '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists($bin);
            $run = [];
            exec(escapeshellarg($bin).' 2>&1', $run, $runRc);
            $combined = implode("\n", $run);
            $this->assertNotSame(0, $runRc, $combined);
            $this->assertStringContainsString('PHPC_RUNTIME_ASSERT M1', $combined);
            $this->assertStringContainsString('underflow', $combined);
            $this->assertStringNotContainsString($marker, $combined);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testInjectedSharedWriteAbortsWithM5(): void
    {
        $marker = 'should-not-print-m5-'.bin2hex(random_bytes(4));
        $src = "<?php\necho \"{$marker}\\n\";\n";
        $suffix = getmypid().'_'.bin2hex(random_bytes(4));
        $path = sys_get_temp_dir().'/phpc_runtime_assert_m5_'.$suffix.'.php';
        $bin = sys_get_temp_dir().'/phpc_runtime_assert_m5_'.$suffix.'.bin';
        file_put_contents($path, $src);
        @unlink('/tmp/phpc-last.ll');
        $env = 'PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg(sys_get_temp_dir().'/phpc-assert-cache-m5-'.$suffix)
            .' PHP_COMPILER_RUNTIME_ASSERT=1 PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE=1 PHP_COMPILER_DUMP_IR=1';
        try {
            $cmd = $env.' '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists($bin);
            $run = [];
            exec(escapeshellarg($bin).' 2>&1', $run, $runRc);
            $combined = implode("\n", $run);
            $this->assertNotSame(0, $runRc, $combined);
            $this->assertStringContainsString('PHPC_RUNTIME_ASSERT M5', $combined);
            $this->assertStringContainsString('shared container', $combined);
            $this->assertStringNotContainsString($marker, $combined);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testCowArrayWriteUnderAssertDoesNotFalsePositive(): void
    {
        $suffix = getmypid().'_'.bin2hex(random_bytes(4));
        $path = sys_get_temp_dir().'/phpc_runtime_assert_cow_'.$suffix.'.php';
        $bin = sys_get_temp_dir().'/phpc_runtime_assert_cow_'.$suffix.'.bin';
        file_put_contents($path, "<?php\n\$a = [1, 2];\n\$b = \$a;\n\$a[0] = 9;\necho \$a[0], \$b[0], \"\\n\";\n");
        @unlink('/tmp/phpc-last.ll');
        $env = 'PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg(sys_get_temp_dir().'/phpc-assert-cache-cow-'.$suffix)
            .' PHP_COMPILER_RUNTIME_ASSERT=1 PHP_COMPILER_DUMP_IR=1';
        try {
            $cmd = $env.' '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists($bin);
            $ir = (string) @file_get_contents('/tmp/phpc-last.ll');
            $this->assertStringContainsString('__hashtable__grow', $ir);
            $this->assertStringContainsString('__ref__assert_exclusive', $ir);
            $this->assertMatchesRegularExpression(
                '/define[^\n]*__hashtable__grow[\s\S]{0,4000}?__ref__assert_exclusive/',
                $ir,
                'grow must call assert_exclusive under ASSERT'
            );
            $run = [];
            exec(escapeshellarg($bin).' 2>&1', $run, $runRc);
            $combined = implode("\n", $run);
            $this->assertSame(0, $runRc, $combined);
            $this->assertStringContainsString('91', $combined);
            $this->assertStringNotContainsString('PHPC_RUNTIME_ASSERT', $combined);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
