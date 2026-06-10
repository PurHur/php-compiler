<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * JIT pending exception/error buffers are LLVM in PHP, not lib/AOT/runtime/*.c (#5364, #5373, #5374).
 *
 * @group llvm
 */
final class ExceptionBridgeTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testNoLegacyRaiseRuntimeSourcesInLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_type_error_raise.c', $linker);
        $this->assertStringNotContainsString('phpc_error_raise.c', $linker);
        $this->assertStringNotContainsString('phpc_readonly_raise.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_type_error_raise.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_error_raise.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_readonly_raise.c');
    }

    public function testBridgeClassesExposeLifecycleApi(): void
    {
        foreach (
            [
                ExceptionBridge::class => [
                    'ensureLinked',
                    'bindJitEngine',
                    'clearPendingAtRunEntry',
                    'throwPendingIfAny',
                    'emitTypeError',
                    'emitTypeErrorAndAbort',
                ],
                ErrorBridge::class => [
                    'ensureLinked',
                    'bindJitEngine',
                    'clearPendingAtRunEntry',
                    'throwPendingIfAny',
                    'emitError',
                ],
                ReadonlyBridge::class => [
                    'ensureLinked',
                    'bindJitEngine',
                    'clearPendingAtRunEntry',
                    'throwPendingIfAny',
                    'emitReadonlyViolation',
                ],
            ] as $class => $methods
        ) {
            foreach ($methods as $method) {
                $this->assertTrue(method_exists($class, $method), $class.'::'.$method);
            }
        }
    }
}
