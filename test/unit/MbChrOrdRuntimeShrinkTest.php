<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * mb_chr()/mb_ord() JIT/AOT fold routes through JitMbChrOrd (#30759).
 */
final class MbChrOrdRuntimeShrinkTest extends TestCase
{
    public function testMbChrCallUsesJitMbChrOrdFold(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/mb_chr.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('JitMbChrOrd::tryChrFold', $source);
        $foldPos = strpos($source, 'JitMbChrOrd::tryChrFold');
        $throwPos = strpos($source, "throw new \\LogicException('mb_chr() is not lowered for JIT/AOT");
        $this->assertNotFalse($foldPos);
        $this->assertNotFalse($throwPos);
        $this->assertLessThan($throwPos, $foldPos, 'fold before unsupported throw');
    }

    public function testMbOrdCallUsesJitMbChrOrdFold(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/mb_ord.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('JitMbChrOrd::tryOrdFold', $source);
        $foldPos = strpos($source, 'JitMbChrOrd::tryOrdFold');
        $throwPos = strpos($source, "throw new \\LogicException('mb_ord() is not lowered for JIT/AOT");
        $this->assertNotFalse($foldPos);
        $this->assertNotFalse($throwPos);
        $this->assertLessThan($throwPos, $foldPos, 'fold before unsupported throw');
    }

    public function testJitMbChrOrdUsesVmMbstring(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/JitMbChrOrd.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('VmMbstring::chr', $source);
        $this->assertStringContainsString('VmMbstring::ord', $source);
        $this->assertStringContainsString('final class JitMbChrOrd', $source);
    }
}
