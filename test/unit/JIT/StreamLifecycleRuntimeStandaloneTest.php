<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamLifecycle;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * is_resource/fclose/feof/fflush LLVM helpers must lower without C symbols in phpc_stream.c (#5343).
 *
 * @group aot-lint
 */
final class StreamLifecycleRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamLifecycleHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamLifecycle::ensureLinked($ctx);

        foreach ([
            '__compiler_is_resource',
            '__compiler_fclose',
            '__compiler_feof',
            '__compiler_fflush',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
