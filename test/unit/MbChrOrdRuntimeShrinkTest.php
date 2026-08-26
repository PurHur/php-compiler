<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * mb_chr()/mb_ord() JIT/AOT fold routes through JitMbChrOrd (#30759).
 */
final class MbChrOrdRuntimeShrinkTest extends TestCase
{
    public function testMbChrCallUsesJitMbChrOrdInvoke(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/mb_chr.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('JitMbChrOrd::invokeChr', $source);
        $this->assertStringNotContainsString(
            "throw new \\LogicException('mb_chr() is not lowered for JIT/AOT",
            $source
        );
    }

    public function testMbOrdCallUsesJitMbChrOrdInvoke(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/mb_ord.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('JitMbChrOrd::invokeOrd', $source);
        $this->assertStringNotContainsString(
            "throw new \\LogicException('mb_ord() is not lowered for JIT/AOT",
            $source
        );
    }

    public function testJitMbChrOrdUsesVmMbstringAndInvoke(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/JitMbChrOrd.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('VmMbstring::chr', $source);
        $this->assertStringContainsString('VmMbstring::ord', $source);
        $this->assertStringContainsString('function invokeOrd', $source);
        $this->assertStringContainsString('function tryChrFold', $source);
        $this->assertStringContainsString('encodingPtr', $source);
        $this->assertStringContainsString('MbChrOrdRuntime::ensureLinked', $source);
        $this->assertStringContainsString('final class JitMbChrOrd', $source);
    }
}
