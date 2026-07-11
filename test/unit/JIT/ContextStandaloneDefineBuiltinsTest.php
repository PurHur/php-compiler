<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\AssertFail;
use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #13137: standalone AOT must link stream/assert ABI when lowering touches them.
 *
 * @group aot-lint
 */
final class ContextStandaloneDefineBuiltinsTest extends TestCase
{
    public function testJitLoweringEnsuresStreamLifecycleAbiBeforeLookup(): void
    {
        $root = \dirname(__DIR__, 3);
        $this->assertStringContainsString(
            'StreamLifecycleRuntime::ensureLinked',
            (string) \file_get_contents($root.'/ext/standard/JitIsResource.php'),
            '#13137'
        );
        $this->assertStringContainsString(
            'StreamReadRuntime::ensureLinked',
            (string) \file_get_contents($root.'/ext/standard/JitFtell.php'),
            '#13137'
        );
    }

    public function testLazyLinkDefinesBootstrapCompileAbiSymbols(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamLifecycleRuntime::ensureLinked($ctx);
        StreamReadRuntime::ensureLinked($ctx);
        AssertFail::ensureStandaloneBodies($ctx);

        foreach ([
            '__compiler_is_resource',
            '__compiler_fflush',
            '__compiler_ftell',
            '__compiler_stream_get_contents',
            '__compiler_fseek',
            '__compiler_jit_raise_assertion_error',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
