<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStripTags;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * AOT standalone must define strip_tags runtime in LLVM, not superglobals_refresh.c.
 *
 * @group aot-lint
 */
final class SuperglobalsStripTagsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesStripTagsRuntimeHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringStripTags::ensureStandaloneBodies($ctx);

        foreach (
            [
                '__compiler_strip_tags',
                '__phpc_st_parse_allowed',
                '__phpc_st_extract_tag_name',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name.' must be linked for standalone AOT');
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name.' must have LLVM body');
        }
    }

    public function testSuperglobalsRefreshCDoesNotDefineStripTagsRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
        $this->assertStringNotContainsString('st_parse_allowed', $source);
        $this->assertStringNotContainsString('st_extract_tag_name', $source);
        $this->assertStringNotContainsString('st_find_substr', $source);
        $this->assertStringNotContainsString('__string__ *__compiler_strip_tags', $source);
        $this->assertStringContainsString('__compiler_strip_tags', $source);
    }
}
