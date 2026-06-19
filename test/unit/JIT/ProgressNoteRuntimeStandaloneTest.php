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

    /** Issue #7146 / #7360: C progress TU must stay async-signal-safe handler only — no buffer writers. */
    public function testProgressCRuntimeIsFrozenThinAbi(): void
    {
        $source = file_get_contents(__DIR__.'/../../../lib/AOT/runtime/phpc_progress.c');
        $this->assertIsString($source);

        $lines = substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(40, $lines, 'phpc_progress.c must remain a thin ABI (handler + extern decls)');

        foreach (['sprintf', 'snprintf', 'strcpy', 'strncpy', 'memcpy', 'malloc', 'fopen', 'fprintf'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden.'(', $source, 'progress formatting belongs in ProgressJitHelper / ProgressNoteRuntime bridge');
        }

        $this->assertStringContainsString('phpc_segv_handler', $source);
        $this->assertStringContainsString('extern char phpc_last_progress', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!extern )char phpc_last_progress\s*\[/',
            $source,
            'buffer definition lives in LLVM globals, not C'
        );
    }
}
