<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** SessionLifecycleRuntime generate_new_id routes through SessionCreateIdJitHelper PHP (#9446). */
final class SessionLifecycleRuntimeShrinkTest extends TestCase
{
    public function testGenerateNewIdUsesSessionCreateIdJitHelperNotHexLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionLifecycleRuntime.php');
        $this->assertStringContainsString('ensureRandomIdStringLinked', $source);
        $this->assertStringContainsString('phpc_session_random_id_string', $source);
        $this->assertStringContainsString('SessionStorageGlobals::emitCallEnsureDefaults', $source);
        $this->assertStringNotContainsString('emitEnsureDefaultSessionName', $source);
        $this->assertStringNotContainsString('__compiler_random_bytes', $source);
        $this->assertStringNotContainsString('sgen_loop_head', $source);
        $this->assertStringNotContainsString('HEX_TABLE', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(461, $lineCount);
        $this->assertGreaterThan(25, 461 - $lineCount);
    }
}
