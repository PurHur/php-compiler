<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamMeta;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stream_get_meta_data/set_blocking LLVM helpers must lower for AOT (#6007).
 *
 * @group aot-lint
 */
final class StreamMetaRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamMetaHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamMeta::ensureLinked($ctx);

        foreach ([
            '__compiler_stream_get_meta_data',
            '__compiler_stream_set_blocking',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
