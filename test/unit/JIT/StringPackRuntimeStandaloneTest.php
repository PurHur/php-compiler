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
    public function testRuntimeShrinkRoutesPackThroughPhpHelper(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/pack_jit_runtime.c');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPackJit.php');
        $this->assertStringContainsString('StringPackJit', $jit);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/PackJitRuntime.php');
        $this->assertStringContainsString('StringPack', $runtime);
        $this->assertStringNotContainsString('pack_jit_runtime.c', $runtime);
        $bridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPack.php');
        $this->assertStringContainsString('PackJitHelper', $bridge);
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
