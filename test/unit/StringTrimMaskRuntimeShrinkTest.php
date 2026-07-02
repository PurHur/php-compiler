<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** __phpc_char_in_mask JIT routes through CharInMaskJitHelper PHP not inline LLVM (#14908). */
final class StringTrimMaskRuntimeShrinkTest extends TestCase
{
    public function testStringTrimMaskUsesJitHelperBridgeNotInlineLlvm(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTrimMask.php');
        $this->assertStringContainsString('CharInMaskJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringNotContainsString('implementCharInMask', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/CharInMaskJitHelper.php');
        $this->assertStringContainsString('VmString::charInTrimMask', $helper);
    }
}
