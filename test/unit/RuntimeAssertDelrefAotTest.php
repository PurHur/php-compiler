<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Config;
use PHPCompiler\JIT\Builtin\Refcount;
use PHPUnit\Framework\TestCase;

/**
 * PHPC_RUNTIME_ASSERT=1 emits M1 (rc > 0 on delref) and the inject probe aborts (#36397).
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
        $this->assertStringContainsString('zend_gc.c', $doc);
        $this->assertStringContainsString('PHP_COMPILER_RUNTIME_ASSERT', $doc);
    }

    public function testRefcountPhpEmitsM1Guard(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/Refcount.php');
        $this->assertStringContainsString('emitRuntimeAssertDelrefUnderflow', $src);
        $this->assertStringContainsString('phpc_runtime_assert_fail', $src);
        $this->assertStringContainsString('PHPC_RUNTIME_ASSERT M%d', $src);
        $this->assertStringContainsString('INT_SLE', $src);
    }

    public function testEnabledReadsAliasAndConfigKnob(): void
    {
        $prev = [getenv('PHP_COMPILER_RUNTIME_ASSERT'), getenv('PHPC_RUNTIME_ASSERT')];
        try {
            putenv('PHP_COMPILER_RUNTIME_ASSERT');
            putenv('PHPC_RUNTIME_ASSERT');
            unset($_ENV['PHP_COMPILER_RUNTIME_ASSERT'], $_SERVER['PHP_COMPILER_RUNTIME_ASSERT']);
            $this->assertFalse(Refcount::runtimeAssertEnabled());
            putenv('PHPC_RUNTIME_ASSERT=1');
            $this->assertTrue(Refcount::runtimeAssertEnabled());
            putenv('PHPC_RUNTIME_ASSERT');
            putenv('PHP_COMPILER_RUNTIME_ASSERT=1');
            $this->assertTrue(Refcount::runtimeAssertEnabled());
            $this->assertArrayHasKey('PHP_COMPILER_RUNTIME_ASSERT', Config::registry());
            $this->assertArrayHasKey('PHP_COMPILER_ASAN', Config::registry());
        } finally {
            foreach (['PHP_COMPILER_RUNTIME_ASSERT' => $prev[0], 'PHPC_RUNTIME_ASSERT' => $prev[1]] as $name => $val) {
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
        $src = <<<'PHP'
        <?php
        echo "should-not-print\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_runtime_assert_m1_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_runtime_assert_m1_'.getmypid().'.bin';
        file_put_contents($path, $src);
        $env = 'PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg(sys_get_temp_dir().'/phpc-assert-cache-'.getmypid())
            .' PHP_COMPILER_RUNTIME_ASSERT=1 PHP_COMPILER_RUNTIME_ASSERT_INJECT_DOUBLE_DELREF=1 PHP_COMPILER_DUMP_IR=1';
        try {
            $cmd = $env.' '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists($bin);
            $ll = is_file('/tmp/phpc-last.ll') ? (string) file_get_contents('/tmp/phpc-last.ll') : implode("\n", $out);
            $this->assertStringContainsString('PHPC_RUNTIME_ASSERT M%d', $ll);
            $this->assertStringContainsString('phpc_runtime_assert_inject_double_delref', $ll);
            $run = [];
            exec(escapeshellarg($bin).' 2>&1', $run, $runRc);
            $combined = implode("\n", $run);
            $this->assertNotSame(0, $runRc, $combined);
            $this->assertStringContainsString('PHPC_RUNTIME_ASSERT M1', $combined);
            $this->assertStringNotContainsString('should-not-print', $combined);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
