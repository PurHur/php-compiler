<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamRead;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stream read/seek/lock LLVM helpers must lower without C symbols in phpc_stream.c (#5343 phase 4).
 *
 * @group aot-lint
 */
final class StreamReadRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamReadHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamRead::ensureLinked($ctx);

        foreach ([
            '__compiler_flock',
            '__compiler_fpassthru',
            '__compiler_ftruncate',
            '__compiler_ftell',
            '__compiler_fgetc',
            '__compiler_fgets',
            '__compiler_stream_get_line',
            '__compiler_fseek',
            '__compiler_stream_get_contents',
            '__compiler_stream_copy_to_stream',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
