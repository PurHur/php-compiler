<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringConvertUu;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #30811: JIT/AOT convert_uu* routes through ConvertUuJitHelper + VmConvertUu bundle.
 *
 * @group aot-lint
 */
final class ConvertUuRuntimeStandaloneTest extends TestCase
{
    public function testImplementDefinesConvertUuAbiForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringConvertUu::implement($ctx);

        $encode = $ctx->lookupFunction('__compiler_convert_uuencode');
        $decode = $ctx->lookupFunction('__compiler_convert_uudecode');
        $this->assertNotNull($encode);
        $this->assertNotNull($decode);
        $this->assertGreaterThan(0, $encode->countBasicBlocks());
        $this->assertGreaterThan(0, $decode->countBasicBlocks());
    }

    public function testStringConvertUuRoutesThroughVmConvertUuBundle(): void
    {
        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringConvertUu.php');
        $this->assertStringContainsString('VmConvertUu.php', $runtimeSource);
        $this->assertStringContainsString('ensureCompiledBundle', $runtimeSource);
        $this->assertStringContainsString('ConvertUuJitHelper', $runtimeSource);
        $this->assertStringNotContainsString('JitConvertUuKernel', $runtimeSource);
    }
}
