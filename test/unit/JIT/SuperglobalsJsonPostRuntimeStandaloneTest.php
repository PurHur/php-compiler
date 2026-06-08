<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringJsonDecode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #7389: AOT standalone must define JSON POST helper without C bodies
 * in superglobals_refresh.c (PHP LLVM via StringJsonDecodeJit).
 *
 * @group aot-lint
 */
final class SuperglobalsJsonPostRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesJsonPostHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringJsonDecode::ensureStandaloneBodies($ctx);

        foreach (
            [
                '__phpc_json_parse_post_body',
                '__phpc_json_parse_object',
                '__phpc_json_skip_ws',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name.' must be linked for standalone AOT');
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name.' must have LLVM body');
        }
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }
}
