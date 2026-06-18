<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** ProgressNoteRuntime must route env-file writes through ProgressJitHelper PHP (#9521). */
final class ProgressNoteRuntimeShrinkTest extends TestCase
{
    public function testProgressNoteRuntimeUsesProgressJitHelperNotLlvmFileWrites(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProgressNoteRuntime.php');
        $this->assertStringContainsString('ProgressJitHelper', $source);
        $this->assertStringNotContainsString('emitWriteEnvFile', $source);
        $this->assertStringNotContainsString("'getenv'", $source);
        $this->assertStringNotContainsString("'fopen'", $source);
        $this->assertStringNotContainsString("'fwrite'", $source);
    }

    public function testProgressNoteRuntimeLlvmHoldsStandaloneFileWriteFallback(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProgressNoteRuntimeLlvm.php');
        $this->assertStringContainsString('emitWriteEnvFile', $llvm);
        $this->assertStringContainsString('fopen', $llvm);
    }
}
