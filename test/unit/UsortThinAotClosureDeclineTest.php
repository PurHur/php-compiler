<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPUnit\Framework\TestCase;

/** Thin AOT usort/uksort Closure decline message (#24142). */
final class UsortThinAotClosureDeclineTest extends TestCase
{
    public function testThinAotClosureRejectionMessageIsExplicit(): void
    {
        $msg = UsortCallbackPolicy::thinAotClosureRejectionMessage('usort');
        $this->assertStringContainsString('usort() with a Closure comparator', $msg);
        $this->assertStringContainsString('thin standalone AOT', $msg);
        $this->assertStringNotContainsString('duplicatefrom', strtolower($msg));
        $this->assertStringNotContainsString('undefined method', strtolower($msg));

        $uk = UsortCallbackPolicy::thinAotClosureRejectionMessage('uksort');
        $this->assertStringContainsString('uksort() with a Closure comparator', $uk);
    }

    public function testDuplicateFromNestedHandlerRegistered(): void
    {
        $this->assertTrue(
            \PHPCompiler\JIT\NestedVmVariableMethodLlvm::isNestedVariableMethod('duplicatefrom')
        );
    }
}
