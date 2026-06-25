<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ObOutput;
use PHPCompiler\JIT\Builtin\ObOutputRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5314: AOT standalone must define OB helpers without phpc_ob.c.
 *
 * @group aot-lint
 */
final class ObOutputRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesObOutputForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ObOutputRuntime::ensureLinked($ctx);

        foreach (
            [
                '__phpc_ob_start',
                '__phpc_ob_get_level',
                '__phpc_ob_buffer_used_at',
                '__phpc_ob_append_bytes',
                '__phpc_ob_get_clean',
                '__phpc_ob_end_flush',
                '__phpc_ob_end_all',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }

        // Issue #11437: repeated registerExternals must keep implemented bodies linkable.
        $obEndAll = $ctx->lookupFunction('__phpc_ob_end_all');
        $this->assertGreaterThan(0, $obEndAll->countBasicBlocks());
        ObOutput::registerExternals($ctx);
        ObOutput::registerExternals($ctx);
        $after = $ctx->lookupFunction('__phpc_ob_end_all');
        $this->assertGreaterThan(0, $after->countBasicBlocks());
        $this->assertSame($obEndAll, $after);
    }
}
