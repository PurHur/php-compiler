<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * JitHelperAbiBridge NestedJIT via JitVmHelperLink::ensureCompiled (#26347 / peer #26333).
 */
final class JitHelperAbiBridgeRuntimeShrinkTest extends TestCase
{
    public function testJitHelperAbiBridgeUsesEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JitHelperAbiBridge.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testExceptionAndReturnPendingStillRouteThroughBridge(): void
    {
        $exception = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ExceptionThrowRuntime.php');
        $returnPending = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ReturnPendingRuntime.php');
        $this->assertStringContainsString('JitHelperAbiBridge::implement', $exception);
        $this->assertStringContainsString('JitHelperAbiBridge::implement', $returnPending);
        // implement() no longer takes a separate basename (path alone feeds ensureCompiled).
        $this->assertDoesNotMatchRegularExpression(
            "/JitHelperAbiBridge::implement\\(\\s*\\\$context,\\s*self::HELPER_PATH,\\s*'[^']+\\.php'/",
            $exception
        );
        $this->assertDoesNotMatchRegularExpression(
            "/JitHelperAbiBridge::implement\\(\\s*\\\$context,\\s*self::HELPER_PATH,\\s*'[^']+\\.php'/",
            $returnPending
        );
    }
}
