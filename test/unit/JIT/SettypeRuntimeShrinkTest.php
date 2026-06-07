<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5938: settype() JIT/AOT lowers from ext/standard/JitSettype.php — no phpc_settype.c.
 *
 * @group aot-lint
 */
final class SettypeRuntimeShrinkTest extends TestCase
{
    public function testRuntimeShrinkRemovesSettypeC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_settype.c');

        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_settype.c', $linker);
        $this->assertStringNotContainsString('phpc_settype', $linker);

        $jitSettype = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSettype.php');
        $this->assertStringContainsString('final class JitSettype', $jitSettype);
        $this->assertStringContainsString('convertInPlace', $jitSettype);

        $settype = (string) file_get_contents(__DIR__.'/../../../ext/standard/settype.php');
        $this->assertStringContainsString('JitSettype::invoke', $settype);
    }
}
