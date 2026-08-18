<?php

declare(strict_types=1);

namespace test\unit;

use PHPCompiler\ext\standard\VmStreamIncludeOpenPolicy;
use PHPUnit\Framework\TestCase;

/** Issue #32104 — script/include stream opens vs allow_url_include. */
final class StreamIncludeOpenPolicyTest extends TestCase
{
    public function testDataUriIsUrlWrapper(): void
    {
        $this->assertTrue(VmStreamIncludeOpenPolicy::isUrlWrapper('data://text/plain,x'));
        $this->assertFalse(VmStreamIncludeOpenPolicy::isUrlWrapper('/tmp/foo.php'));
        $this->assertFalse(VmStreamIncludeOpenPolicy::isUrlWrapper('relative.php'));
    }

    public function testBlockedForScriptOpenWhenAllowUrlIncludeOff(): void
    {
        $this->assertTrue(
            VmStreamIncludeOpenPolicy::blockedForScriptOpen('data://text/plain,x', null)
        );
        $this->assertFalse(
            VmStreamIncludeOpenPolicy::blockedForScriptOpen('/etc/passwd', null)
        );
    }

    public function testWrapperDisabledMessageMatchesZendShape(): void
    {
        $msg = VmStreamIncludeOpenPolicy::wrapperDisabledMessage(
            'php_strip_whitespace',
            'data://text/plain,x'
        );
        $this->assertSame(
            'php_strip_whitespace(): data:// wrapper is disabled in the server configuration by allow_url_include=0',
            $msg
        );
    }
}
