<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VmEscapeshell must not delegate to host ext/mbstring (#8019, pairs #4861/#7912). */
final class VmEscapeshellRuntimeShrinkTest extends TestCase
{
    public function testVmEscapeshellDoesNotReferenceHostMbstring(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmEscapeshell.php');
        $this->assertStringContainsString('VmString::utf8CharByteWidth', $source);
        $this->assertStringNotContainsString('\\mb_strlen(', $source);
        $this->assertStringNotContainsString('\\mb_substr(', $source);
        $this->assertStringNotContainsString("function_exists('mb_strlen')", $source);
    }

    public function testUtf8MultibyteEscapeshellargMatchesNativeWidth(): void
    {
        $quoted = \PHPCompiler\ext\standard\VmEscapeshell::escapeshellarg('日本語');
        $this->assertSame("'日本語'", $quoted);
    }
}
