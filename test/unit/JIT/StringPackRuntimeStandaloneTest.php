<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\PackJitRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6607: pack() LLVM helpers replace pack_jit_runtime.c.
 *
 * @group aot-lint
 */
final class StringPackRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesPackJitC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/pack_jit_runtime.c');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPackJit.php');
        $this->assertStringContainsString('__compiler_pack', $jit);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/PackJitRuntime.php');
        $this->assertStringContainsString('StringPackJit', $runtime);
        $this->assertStringNotContainsString('pack_jit_runtime.c', $runtime);
        $engine = (string) file_get_contents(__DIR__.'/../../../ext/standard/PackEngine.php');
        $this->assertStringContainsString('PackEngine', $engine);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedDefinesPackForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        PackJitRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_pack');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
