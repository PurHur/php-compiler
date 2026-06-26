<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmGetrusageNative;
use PHPCompiler\ext\standard\VmProcess;
use PHPUnit\Framework\TestCase;

/** VmGetrusageNative routes through VmGetrusagePure without host \\getrusage() (#5388, #8970). */
final class VmGetrusageNativeTest extends TestCase
{
    public function testVmProcessUsesNativeWithoutHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcess.php');
        $this->assertStringContainsString('VmGetrusageNative::getrusage', $source);
        $this->assertStringNotContainsString("function_exists('getrusage')", $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\getrusage\\s*\\(/', $source);
    }

    public function testNormalizeWhoMapsChildrenMode(): void
    {
        $this->assertSame(-1, VmGetrusageNative::normalizeWho(1));
        $this->assertSame(0, VmGetrusageNative::normalizeWho(0));
    }

    public function testNativeGetrusageShapeOnLinux(): void
    {
        if (!VmGetrusageNative::available()) {
            $this->markTestSkipped('/proc/self/stat unavailable');
        }

        $usage = VmGetrusageNative::getrusage(0);
        $this->assertIsArray($usage);
        $this->assertArrayHasKey('ru_maxrss', $usage);
        $this->assertArrayHasKey('ru_utime.tv_sec', $usage);

        $children = VmGetrusageNative::getrusage(1);
        $this->assertIsArray($children);
        $this->assertArrayHasKey('ru_maxrss', $children);
    }

    public function testVmProcessGetrusageReturnsHashtable(): void
    {
        if (!VmGetrusageNative::available()) {
            $this->markTestSkipped('/proc/self/stat unavailable');
        }

        $ht = VmProcess::getrusage(0);
        $this->assertNotFalse($ht);
        $this->assertGreaterThan(0, $ht->getNumElements());
    }
}
