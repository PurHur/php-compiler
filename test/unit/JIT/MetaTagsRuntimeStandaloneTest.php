<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MetaTagsRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #4608 / #9338: get_meta_tags must link via MetaTagsJitHelper PHP bridge, not LLVM HTML walker.
 *
 * @group aot-lint
 */
final class MetaTagsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesMetaTagsBridgeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        MetaTagsRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_get_meta_tags');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        $this->assertNull($ctx->module->getNamedFunction('__compiler_parse_meta_tags_html'));
        $this->assertNull($ctx->module->getNamedFunction('__compiler_meta_extract_attr'));
        $this->assertNull($ctx->module->getNamedFunction('__compiler_meta_normalize_name'));
    }
}
