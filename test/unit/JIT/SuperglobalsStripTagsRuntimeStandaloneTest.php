<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStripTags;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9196: standalone AOT keeps LLVM strip_tags; JIT path uses StripTagsJitHelper.
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

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }
}
