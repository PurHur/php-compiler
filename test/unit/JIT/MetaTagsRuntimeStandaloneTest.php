<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MetaTagsRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #4608: get_meta_tags LLVM helpers must link without C runtime (#3703).
 *
 * @group aot-lint
 */
final class MetaTagsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesMetaTagsHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        MetaTagsRuntime::ensureLinked($ctx);

        foreach (
            [
                '__compiler_get_meta_tags',
                '__compiler_parse_meta_tags_html',
                '__compiler_meta_extract_attr',
                '__compiler_meta_normalize_name',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
