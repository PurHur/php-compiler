<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** iconv_strlen/strpos/strrpos JIT routes through IconvStringJitHelper PHP (#34277). */
final class IconvSearchRuntimeShrinkTest extends TestCase
{
    public function testIconvStringJitHelperHasSearchArgvPeels(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/iconv/IconvStringJitHelper.php');
        $this->assertStringContainsString('strlenArgv', $source);
        $this->assertStringContainsString('strposArgv', $source);
        $this->assertStringContainsString('strrposArgv', $source);
        $this->assertStringContainsString('StringStrpos::NOT_FOUND', $source);
        $this->assertStringNotContainsString('return VmIconv', $source);
    }

    public function testStringIconvSubstrLinksSearchHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringIconvSubstr.php');
        $this->assertStringContainsString('::strlenArgv', $source);
        $this->assertStringContainsString('::strposArgv', $source);
        $this->assertStringContainsString('::strrposArgv', $source);
        $this->assertStringContainsString('strlenHelper', $source);
        $this->assertStringContainsString('strposHelper', $source);
        $this->assertStringContainsString('strrposHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
    }

    public function testJitIconvStringUsesCallHelperForSearch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/iconv/JitIconvString.php');
        $this->assertStringContainsString('StringIconvSubstr::strlenHelper', $source);
        $this->assertStringContainsString('StringIconvSubstr::strposHelper', $source);
        $this->assertStringContainsString('StringIconvSubstr::strrposHelper', $source);
        $this->assertStringContainsString('StringStrpos::boxFoundOffset', $source);
        $this->assertStringNotContainsString(
            'iconv_strpos() JIT requires compile-time string arguments',
            $source
        );
    }
}
