<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #7912: VmMbstring/mb_strlen must not delegate to host ext/mbstring. */
final class MbstringRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testVmMbstringDoesNotReferenceHostMbstring(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/mbstring/VmMbstring.php');
        $this->assertStringNotContainsString('\\mb_convert_case(', $source);
        $this->assertStringNotContainsString('\\mb_stripos(', $source);
        $this->assertStringNotContainsString('\\mb_stristr(', $source);
        $this->assertStringNotContainsString('\\mb_strrpos(', $source);
        $this->assertStringNotContainsString('\\mb_strrchr(', $source);
        $this->assertStringNotContainsString('\\mb_strrichr(', $source);
        $this->assertStringNotContainsString('\\mb_strripos(', $source);
        $this->assertStringNotContainsString('\\mb_check_encoding(', $source);
        $this->assertStringNotContainsString("function_exists('mb_", $source);
    }

    public function testMbStrlenDoesNotReferenceHostMbstring(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/mbstring/mb_strlen.php');
        $this->assertStringContainsString('VmString::utf8CharLength', $source);
        $this->assertStringNotContainsString('\\mb_strlen(', $source);
        $this->assertStringNotContainsString("function_exists('mb_strlen')", $source);
    }
}
