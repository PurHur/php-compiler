<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Exception thrown from __get must reach surrounding try/catch (#25911).
 *
 * Zend/zend_object_handlers.c — zend_std_read_property / magic __get exception propagation.
 */
final class MagicGetThrowCatchableTest extends TestCase
{
    public function testMagicGetThrowCatchableOnVm(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/maintainer_gap_magic_get_throw_catchable.php';
        $this->assertFileExists($repro);

        $cmd = [PHP_BINARY, $root.'/bin/vm.php', $repro];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim((string) $stderr));
        $this->assertSame("caught=RuntimeException:get missing\nafter\n", $stdout);
    }

    /**
     * @group llvm
     */
    public function testMagicGetThrowCatchableOnJit(): void
    {
        require_once dirname(__DIR__).'/LlvmToolchain.php';
        $root = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($root);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM unavailable');
        }

        $repro = $root.'/test/repro/maintainer_gap_magic_get_throw_catchable.php';
        $cmd = [PHP_BINARY, $root.'/bin/jit.php', $repro];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim((string) $stderr));
        $this->assertSame("caught=RuntimeException:get missing\nafter\n", $stdout);
    }
}
