<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** ProgressNoteRuntime must route env-file writes through ProgressJitHelper PHP (#9521, #9795). */
final class ProgressNoteRuntimeShrinkTest extends TestCase
{
    public function testProgressNoteRuntimeEmbedUsesProgressJitHelperNotLlvmFileWrites(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProgressNoteRuntime.php');
        $this->assertStringContainsString('ProgressJitHelper', $source);
        $this->assertStringNotContainsString('emitWriteEnvFile', $source);
        $this->assertStringNotContainsString("'getenv'", $source);
        $this->assertStringNotContainsString("'fopen'", $source);
        $this->assertStringNotContainsString("'fwrite'", $source);
        $this->assertStringContainsString('ensureJitHelperCompiled', $source);
    }

    /** Standalone AOT keeps thin LLVM until nested ProgressJitHelper JIT during defineBuiltins is safe (#10146). */
    public function testProgressNoteRuntimeStandaloneDispatchesToLlvmBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProgressNoteRuntime.php');
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringContainsString('ProgressNoteRuntimeLlvm::implement', $source);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/ProgressNoteRuntimeLlvm.php');
    }
}
