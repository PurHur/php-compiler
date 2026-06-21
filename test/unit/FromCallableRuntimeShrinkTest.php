<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** FromCallable JIT routes TYPE_FROM_CALLABLE through VmFromCallable PHP (#10272). */
final class FromCallableRuntimeShrinkTest extends TestCase
{
    public function testFromCallableHelperRoutesThroughVmFromCallable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/FromCallableHelper.php');
        $this->assertStringContainsString('VmFromCallable', $source);
        $this->assertStringNotContainsString('assertStaticMethodFcc', $source);
        $this->assertStringNotContainsString('wrapCallableProxy', $source);
        $this->assertLessThanOrEqual(25, substr_count($source, "\n") + 1);
        $this->assertFileExists(__DIR__.'/../../lib/VM/VmFromCallable.php');
    }

    public function testVmFromCallableOwnsFccLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmFromCallable.php');
        $this->assertStringContainsString('createClosureVariable', $source);
        $this->assertStringContainsString('assertStaticMethodFcc', $source);
        $this->assertStringContainsString('ClosureSupport::fromCallable', $source);
        $this->assertGreaterThan(150, substr_count($source, "\n") + 1);
    }
}
