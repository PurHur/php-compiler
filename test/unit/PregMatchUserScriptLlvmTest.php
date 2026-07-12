<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** User-script AOT preg routes through LLVM defer path (#16075). */
final class PregMatchUserScriptLlvmTest extends TestCase
{
    public function testPregMatchRuntimeDefersNestedJitForUserScript(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('UserScriptAotDeferNestedJit::shouldDefer', $source);
        $this->assertStringContainsString('PregMatchUserScriptLlvm::implement', $source);
    }

    public function testRuntimeLinksPregAfterUserScriptFlagRestore(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Runtime.php');
        $this->assertStringContainsString('if ($needsPregPrelink && !$deferUserScriptAotInit)', $source);
        $this->assertStringContainsString('if ($needsPregPrelink) {', $source);
        $this->assertStringContainsString('StringPregMatch::ensureLinked($context);', $source);
    }
}
