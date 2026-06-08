<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringParseStrJit;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #7302: AOT standalone must define bracket/delimited-pair helpers without C bodies
 * in superglobals_refresh.c (PHP LLVM via StringParseStrJit).
 *
 * @group aot-lint
 */
final class SuperglobalsBracketRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesBracketHelpers(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringParseStrJit::ensureStandaloneBodies($ctx);

        foreach (
            [
                '__phpc_parse_str_parse_delimited_pairs',
                '__phpc_parse_str_parse_key_brackets',
                '__phpc_parse_str_ensure_child',
                '__phpc_parse_str_set_nested_value',
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
