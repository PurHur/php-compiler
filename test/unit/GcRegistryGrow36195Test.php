<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Growable GC registry for user-script standalone AOT (#36195).
 *
 * @group aot-lint
 */
final class GcRegistryGrow36195Test extends TestCase
{
    public function testNativeRegistryCapacityDoubled(): void
    {
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('CycleCollector::DEFAULT_BUFFER_SIZE', $runtime);
    }

    public function testRegistryHelperHasNoFixed65536Cap(): void
    {
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/GcCollectCyclesRegistryJitHelper.php');
        $this->assertStringNotContainsString('self::$count >= self::MAX_OBJECTS', $helper);
    }

    public function testRegistryRegisterCapMatchesZendBuffer(): void
    {
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('CycleCollector::ROOT_THRESHOLD - 3', $runtime);
    }
}
