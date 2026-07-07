<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ProgressNoteRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6748 / #6777 / #7360 / #9795: AOT standalone uses ProgressJitHelper PHP + LLVM SIGSEGV buffer globals.
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

    /** Issue #11437: standalone main uses buffer-only remember ABI (no ProgressJitHelper broadcast). */
    public function testEnsureLinkedDefinesProgressRememberForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ProgressNoteRuntime::ensureLinked($ctx);

        $remember = $ctx->lookupFunction('__phpc_progress_remember');
        $this->assertGreaterThan(0, $remember->countBasicBlocks());
    }

    public function testEnsureLinkedDefinesProgressBufferGlobals(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ProgressNoteRuntime::ensureLinked($ctx);

        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_last_progress'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_last_progress_len'));
    }

    /** Issue #8560: self-host spine must lower Progress::{noteFunction,notePhase,noteEntry} before PHP bodies compile. */
    public function testEnsureLinkedRegistersProgressStaticProxies(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ProgressNoteRuntime::ensureLinked($ctx);

        foreach ([
            'phpcompiler\\jit\\progress::notefunction',
            'phpcompiler\\jit\\progress::notephase',
            'phpcompiler\\jit\\progress::noteentry',
        ] as $proxy) {
            $this->assertTrue($ctx->functionIsRegistered($proxy), $proxy);
            $this->assertArrayHasKey($proxy, $ctx->functionProxies, $proxy);
        }
    }

    /** Issue #7146 / #7360: SIGSEGV handler is emitted in LLVM IR (write+_exit only). */
    public function testProgressSegvHandlerIsLinkedInLlvm(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ProgressNoteRuntime::ensureLinked($ctx);

        $handler = $ctx->module->getNamedFunction('phpc_segv_handler');
        $this->assertNotNull($handler);
        $this->assertGreaterThan(0, $handler->countBasicBlocks());
    }
}
