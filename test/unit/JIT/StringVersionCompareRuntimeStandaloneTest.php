<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringVersionCompare;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9813: AOT standalone version_compare must use VersionCompareJitHelper PHP, not LLVM parser.
 *
 * @group aot-lint
 */
final class StringVersionCompareRuntimeStandaloneTest extends TestCase
{
    public function testImplementDefinesVersionCompareForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringVersionCompare::implement($ctx);

        $fn = $ctx->lookupFunction('__compiler_version_compare');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testStringVersionCompareRoutesThroughVersionCompareJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVersionCompare.php');
        $this->assertStringContainsString('VersionCompareJitHelper', $source);
        $this->assertStringNotContainsString('emitCanonicalizeVersion', $source);
        $this->assertStringNotContainsString('SPECIAL_FORMS', $source);
    }
}
