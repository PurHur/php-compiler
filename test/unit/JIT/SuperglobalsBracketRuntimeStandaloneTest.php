<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ParseStrRuntime;
use PHPCompiler\JIT\Builtin\SuperglobalRefreshUserScriptLlvm;
use PHPCompiler\JIT\Builtin\StringParseStr;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Superglobals bracket parsing uses PHP refresh helpers, not LLVM __phpc_parse_str_* (#7302, #13429).
 *
 * @group aot-lint
 */
final class SuperglobalsBracketRuntimeStandaloneTest extends TestCase
{
    public function testStandaloneRefreshLinksParseStrRuntimeNotLegacySubhelpers(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringParseStr::ensureStandaloneBodies($ctx);

        $fn = $ctx->module->getNamedFunction('__compiler_parse_str');
        $this->assertNotNull($fn, '__compiler_parse_str must be linked for standalone AOT');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_parse_str must have LLVM bridge body');
    }

    public function testUserScriptRefreshLinksParseStrRuntimeBridgesNotDelimitedLlvm(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SuperglobalRefreshUserScriptLlvm::ensurePrerequisites($ctx);

        foreach (['__compiler_parse_str', '__compiler_parse_cookie_header'] as $name) {
            $bridge = $ctx->module->getNamedFunction($name);
            $this->assertNotNull($bridge, $name.' must be linked for user-script AOT refresh (#18643)');
            $this->assertGreaterThan(0, $bridge->countBasicBlocks(), $name.' must have LLVM bridge body');
        }

        foreach (
            [
                '__phpc_parse_str_parse_delimited_pairs',
                '__phpc_parse_str_parse_key_brackets',
                '__phpc_parse_str_ensure_child',
                '__phpc_parse_str_set_nested_value',
            ] as $name
        ) {
            $legacy = $ctx->module->getNamedFunction($name);
            $this->assertNotNull($legacy, $name.' LLVM linked via ParseStrRuntimeUserScriptCstr (#18855)');
            $this->assertGreaterThan(0, $legacy->countBasicBlocks(), $name.' must have LLVM body');
        }
    }

    public function testParseStrRuntimeStillProvidesMainEntry(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ParseStrRuntime::ensureLinked($ctx);
        $fn = $ctx->module->getNamedFunction('__compiler_parse_str');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }
}
