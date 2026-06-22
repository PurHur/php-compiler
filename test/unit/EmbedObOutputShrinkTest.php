<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\EmbedObJitHelper;
use PHPUnit\Framework\TestCase;

/** EmbedObOutput must drop LLVM format loops; EmbedObJitHelper owns PHP SSOT (#9956). */
final class EmbedObOutputShrinkTest extends TestCase
{
    public function testEmbedObOutputDroppedLlvmFormatLoops(): void
    {
        $output = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EmbedObOutput.php');
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EmbedObEchoBridge.php');
        $this->assertStringNotContainsString('len_loop', $output.$bridge);
        $this->assertStringNotContainsString('unsigendRem', $output.$bridge);
        $this->assertStringContainsString('EmbedObEchoBridge', $output);
        $this->assertStringContainsString('snprintf', $bridge);
        $this->assertLessThan(165, \substr_count($output, "\n") + 1);
    }

    public function testEmbedObJitHelperFormatsIntAndDouble(): void
    {
        $this->assertSame('42', EmbedObJitHelper::formatInt64(42));
        $this->assertSame('-7', EmbedObJitHelper::formatInt64(-7));
        $this->assertSame('3.14', EmbedObJitHelper::formatDouble(3.14));
    }
}
