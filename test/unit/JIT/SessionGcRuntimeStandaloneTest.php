<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9411 / #25916: JIT/AOT session_gc must route file purge through SessionGcJitHelper via JitVmHelperLink.
 *
 * @group aot-lint
 */
final class SessionGcRuntimeStandaloneTest extends TestCase
{
    public function testSessionGcRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/SessionGcRuntime.php');
        $this->assertStringContainsString('SessionGcJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('ss_gc_loop_head', $source);
    }
}
