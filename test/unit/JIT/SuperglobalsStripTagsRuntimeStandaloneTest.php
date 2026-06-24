<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStripTags;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9196 / #9746: AOT standalone strip_tags via StripTagsJitHelper PHP, not LLVM monolith.
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

        $fn = $ctx->lookupFunction('__compiler_strip_tags');
        $this->assertNotNull($fn, '__compiler_strip_tags must be linked for standalone AOT');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_strip_tags must have LLVM bridge body');

        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\StripTagsJitHelper::stripTags')] ?? null,
            'StripTagsJitHelper::stripTags must be compiled into standalone module'
        );

        $this->assertNull($ctx->module->getNamedFunction('__phpc_st_parse_allowed'));
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }
}
