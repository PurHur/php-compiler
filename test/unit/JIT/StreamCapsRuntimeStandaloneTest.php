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

    public function testEnsureLinkedDefinesIsattyBridgeForThinUserScriptAot(): void
    {
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            $this->assertTrue($ctx->isThinStandaloneAotMain());
            StreamCaps::ensureLinked($ctx);
            $fn = $ctx->lookupFunction('__compiler_stream_isatty');
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_stream_isatty');
        } finally {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
            unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
        }
    }

    public function testJitStreamIsattyLazyLinksBridgeBeforeCall(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitStreamIsatty.php');
        $this->assertStringContainsString('StreamCaps::ensureLinked', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
    }
}
