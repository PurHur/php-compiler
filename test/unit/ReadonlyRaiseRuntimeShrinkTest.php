<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** ReadonlyRaise must route pending state through ReadonlyRaiseJitHelper PHP (#9522). */
final class ReadonlyRaiseRuntimeShrinkTest extends TestCase
{
    public function testReadonlyRaiseUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ReadonlyRaise.php');
        $this->assertStringContainsString('ReadonlyRaiseJitHelper', $source);
        $this->assertStringNotContainsString("addGlobal(\$i8, 'phpc_jit_pending_flag')", $source);
        $this->assertStringNotContainsString("addGlobal(\$msgTy, 'phpc_jit_pending_msg')", $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ReadonlyRaiseJitHelper.php');
    }
}
