<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringLz4;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #22602: standalone AOT links lz4 helpers through PHP helper compilation.
 *
 * @group aot-lint
 */
final class Lz4RuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesLz4HelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringLz4::ensureLinked($ctx);

        foreach (
            [
                'phpcompiler\\ext\\lz4\\vmlz4native::compress',
                'phpcompiler\\ext\\lz4\\vmlz4native::uncompress',
                'phpcompiler\\ext\\lz4\\lz4jithelper::compress',
                'phpcompiler\\ext\\lz4\\lz4jithelper::uncompress',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
