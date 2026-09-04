<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #36382: Slim MiddlewareDispatcher::addDeferred anonymous handle — AOT module
 * verify must not fail on parentless __value__ GEPs after try/JUMPIF arms clear
 * insert before publishAfterWrite (ARG_SEND), or after TypeError pend+ret on
 * prop_value_done before sprintf lowerRequiredBoxed.
 *
 * php-src: Zend/zend_execute.c typed properties; Zend/zend_exceptions.c
 *
 * @group llvm
 * @group aot
 */
final class Issue36382AnonMiddlewareHandleAotTest extends TestCase
{
    public function testMiddlewareDispatcherFixtureCompilesUnderLlvmAssert(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = $root
            . '/test/fixtures/aot/projects/slim_hello_36382/vendor/slim/slim/Slim/MiddlewareDispatcher.php';
        if (!is_file($fixture)) {
            $this->markTestSkipped('slim_hello_36382 fixture not set up (run setup-slim-hello-36382.sh)');
        }
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $bin = sys_get_temp_dir() . '/phpc_36382_md_' . getmypid() . '.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $env['PHP_COMPILER_LLVM_ASSERT'] = '1';
        $cmd = [PHP_BINARY, $root . '/bin/compile.php', '-o', $bin, $fixture];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileRc = proc_close($proc);
        if (is_file($bin)) {
            @unlink($bin);
        }
        $this->assertSame(0, $compileRc, 'compile failed: ' . substr((string) $stderr, 0, 1200));
        $this->assertStringNotContainsString('Module verification failed', (string) $stderr);
    }
}
