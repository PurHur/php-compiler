<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ProgressNoteRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6748: AOT standalone must define progress notes without C file-write logic.
 *
 * @group aot-lint
 */
final class ProgressNoteRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesProgressNoteForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ProgressNoteRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__phpc_progress_note');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
