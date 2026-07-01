<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** CallArgv LLVM global is scoped per JIT module, not process-wide (#14459). */
final class CallArgvGlobalsTest extends TestCase
{
    public function testCallArgvGuardsGlobalPerModule(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CallArgv.php');
        $this->assertStringContainsString('$htModule', $source);
        $this->assertStringContainsString('getNamedGlobal', $source);
        $this->assertStringContainsString('self::$htModule === $module', $source);
        $this->assertStringContainsString('ensureGlobal', $source);
    }
}
