<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** iconv_mime_decode() JIT routes through IconvMimeJitHelper PHP (#27424). */
final class IconvMimeRuntimeShrinkTest extends TestCase
{
    public function testIconvMimeDecodeCallUsesJitIconvMime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/iconv/iconv_mime_decode.php');
        $this->assertStringContainsString('JitIconvMime::invoke', $source);
        $this->assertStringNotContainsString('not lowered for JIT/AOT', $source);
    }

    public function testIconvMimeJitHelperDelegatesToVmIconvMime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/iconv/IconvMimeJitHelper.php');
        $this->assertStringContainsString('VmIconvMime::mimeDecode', $source);
        $this->assertStringContainsString('mimeDecodeArgv', $source);
    }

    public function testStringIconvMimeUsesJitVmHelperLinkEnsureBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringIconvMime.php');
        $this->assertStringContainsString('::mimeDecodeArgv', $source);
        $this->assertStringContainsString('__compiler_iconv_mime_decode', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesIconvMimeHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IconvMimeJitHelper.php', $spine);
        $this->assertStringContainsString('JitIconvMime.php', $spine);
        $this->assertStringContainsString('StringIconvMime.php', $spine);
    }
}
