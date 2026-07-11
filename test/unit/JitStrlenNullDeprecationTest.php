<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** #13197: strlen(null) JIT deprecation must emit LLVM IR, not call private TriggerErrorJitHelper. */
final class JitStrlenNullDeprecationTest extends TestCase
{
    public function testJitStrlenUsesJitBuiltinWarningEmitDeprecated(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/types/JitStrlen.php');
        $this->assertStringContainsString('JitBuiltinWarning::emitDeprecated', $source);
        $this->assertStringNotContainsString('TriggerErrorJitHelper::recordAndMaybePrint', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('StringTriggerErrorJit::implement', $source);
        $this->assertStringContainsString('__compiler_trigger_error', (string) file_get_contents(__DIR__.'/../../ext/standard/JitBuiltinWarning.php'));
    }
}
