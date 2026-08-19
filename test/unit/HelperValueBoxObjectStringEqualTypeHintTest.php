<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #32549 — tryValueBoxObjectStringLooseEqual native string is PHPLLVM\Value.
 *
 * Bare `Value $nativeStr` in namespace PHPCompiler\JIT is PHPCompiler\JIT\Value
 * and throws TypeError on every AOT compile (aot-smoke 0/8 leftover of #32544).
 */
final class HelperValueBoxObjectStringEqualTypeHintTest extends TestCase
{
    public function testNativeStrParamIsPhpLlvmValue(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Helper.php');
        $this->assertStringContainsString('#32549', $src);
        $this->assertStringContainsString(
            'PHPLLVM\\Value $nativeStr',
            $src,
            'Helper.php must type-hint LLVM __string__* as PHPLLVM\\Value (#32549)'
        );
        $this->assertStringNotContainsString(
            "        Value \$nativeStr\n",
            $src,
            'Bare Value $nativeStr resolves to PHPCompiler\\JIT\\Value and breaks aot-smoke'
        );
    }
}
