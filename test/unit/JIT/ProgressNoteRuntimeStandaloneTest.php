<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ProgressNoteRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6748 / #6777: AOT standalone defines progress notes in LLVM globals + optional C SIGSEGV ABI.
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

    public function testEnsureLinkedDefinesProgressBufferGlobals(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ProgressNoteRuntime::ensureLinked($ctx);

        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_last_progress'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_last_progress_len'));
    }
}
