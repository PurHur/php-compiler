<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringGetrusage;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5388: AOT standalone must define getrusage helper without phpc_getrusage.c.
 * Issue #9184: JIT getrusage routes through GetrusageJitHelper + VmProcess PHP.
 *
 * @group aot-lint
 */
final class StringGetrusageRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGetrusageForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringGetrusage::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_getrusage');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testStringGetrusageRoutesThroughGetrusageJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringGetrusage.php');
        $this->assertStringContainsString('StringGetrusageRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('FIELD_OFFSETS', $source);
        $this->assertStringNotContainsString("lookupFunction('getrusage')", $source);

        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringGetrusageRuntime.php');
        $this->assertStringContainsString('GetrusageJitHelper', $runtimeSource);

        $helperSource = (string) \file_get_contents(__DIR__.'/../../../ext/standard/GetrusageJitHelper.php');
        $this->assertStringContainsString('VmProcess::getrusage', $helperSource);
    }
}
