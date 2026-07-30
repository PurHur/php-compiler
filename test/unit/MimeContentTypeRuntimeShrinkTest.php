<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MimeContentTypeJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * mime_content_type JIT routes through MimeContentTypeJitHelper PHP (#9236 / #25544).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer #25541).
 */
final class MimeContentTypeRuntimeShrinkTest extends TestCase
{
    public function testMimeContentTypeJitHelperDelegatesToVmMime(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/MimeContentTypeJitHelper.php');
        $this->assertStringContainsString('VmMime::mimeContentTypeFromPath', $source);
        $this->assertStringNotContainsString('strncmp', $source);
    }

    public function testMimeContentTypeRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MimeContentTypeRuntime.php');
        $this->assertStringContainsString('MimeContentTypeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString("lookupFunction('strncmp')", $source);
        $this->assertStringNotContainsString('__compiler_file_get_contents', $source);
        $this->assertStringNotContainsString('literalString', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(100, $lineCount);
        $this->assertGreaterThan(60, 186 - $lineCount);
    }

    public function testMimeContentTypeJitHelperMatchesVmMimeSemantics(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'phpc-mime-');
        $this->assertNotFalse($tmp);
        $path = $tmp.'.php';
        \rename($tmp, $path);
        \file_put_contents($path, "<?php echo 1;\n");

        $this->assertSame('text/x-php', MimeContentTypeJitHelper::mimeContentType($path));
        $this->assertNull(MimeContentTypeJitHelper::mimeContentType('/no/such/phpc-mime-'.bin2hex(random_bytes(4))));

        @\unlink($path);
    }
}
