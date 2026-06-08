<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamResource;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * get_resource_type()/get_resources() LLVM helpers must lower without C symbols in phpc_stream.c (#6821).
 *
 * @group aot-lint
 */
final class StreamResourceRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamResourceHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamResource::ensureLinked($ctx);

        foreach ([
            '__compiler_get_resource_type',
            '__compiler_get_resources',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }

    public function testPhpcStreamCNoLongerDefinesResourceHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/AOT/runtime/phpc_stream.c');
        $this->assertStringNotContainsString('__compiler_get_resource_type(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_get_resources(__string__', $source);
        $this->assertStringContainsString('StreamResourceJit.php', $source);
    }
}
