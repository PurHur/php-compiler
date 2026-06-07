<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamCaps;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stream_isatty/is_local/supports LLVM helpers must lower without C symbols in phpc_stream.c (#5343).
 *
 * @group aot-lint
 */
final class StreamCapsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamCapsHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamCaps::ensureLinked($ctx);

        foreach ([
            '__compiler_stream_isatty',
            '__compiler_stream_is_local',
            '__compiler_stream_supports',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }

    public function testPhpcStreamCNoLongerDefinesCapsHelpers(): void
    {
        $source = file_get_contents(__DIR__.'/../../../lib/AOT/runtime/phpc_stream.c');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('__compiler_stream_isatty', $source);
        $this->assertStringNotContainsString('__compiler_stream_is_local', $source);
        $this->assertStringNotContainsString('__compiler_stream_supports', $source);
        $this->assertStringNotContainsString('phpc_stream_is_url_path', $source);
    }
}
