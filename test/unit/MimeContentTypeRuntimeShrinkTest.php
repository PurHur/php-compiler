<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MimeContentTypeJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * mime_content_type JIT routes through MimeContentTypeJitHelper PHP (#9236 / #25544 / #33034 / #33039).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer #25541).
 * Runtime owns ABI module-locally with insert-block save/restore (peer StringFileGetContents).
 * Sniff is same-file (NestedJIT cannot call VmMime across HELPER_PATH); no NestedJIT strncmp (#33039).
 */
final class MimeContentTypeRuntimeShrinkTest extends TestCase
{
    public function testMimeContentTypeJitHelperUsesNestedJitFileReadAndSameFileSniff(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/MimeContentTypeJitHelper.php');
        $this->assertStringContainsString('@\\file_get_contents', $source);
        $this->assertStringContainsString('self::detectFromBytes', $source);
        $this->assertStringContainsString('decodeDataUri', $source);
        $this->assertStringContainsString("'data:'", $source);
        $this->assertStringContainsString('#34789', $source);
        $this->assertStringContainsString('#33039', $source);
        $this->assertStringNotContainsString('VmMime::detectFromBytes(', $source);
        $this->assertStringNotContainsString('VmFs::fileGetContents', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\strncmp\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\strncasecmp\\s*\\(/', $source);
    }

    public function testMimeContentTypeRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MimeContentTypeRuntime.php');
        $this->assertStringContainsString('#33034', $source);
        $this->assertStringContainsString('StringBase64Decode::ensureLinked', $source);
        $this->assertStringContainsString('#34789', $source);
        $this->assertStringContainsString('MimeContentTypeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::hasNamedBridgeEntry', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('getNamedFunction', $source);
        $this->assertStringContainsString('addFunction', $source);
        $this->assertStringNotContainsString("lookupFunction('strncmp')", $source);
        $this->assertStringNotContainsString('__compiler_file_get_contents', $source);
        $this->assertStringNotContainsString('literalString', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(140, $lineCount);
    }

    public function testMimeContentTypeJitHelperMatchesVmMimeSemantics(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'phpc-mime-');
        $this->assertNotFalse($tmp);
        $path = $tmp.'.php';
        \rename($tmp, $path);
        \file_put_contents($path, "<?php echo 1;\n");

        $this->assertSame('text/x-php', MimeContentTypeJitHelper::mimeContentType($path));
        $this->assertSame('text/plain', MimeContentTypeJitHelper::mimeContentType('/etc/hosts'));
        $this->assertSame(
            'text/plain',
            MimeContentTypeJitHelper::mimeContentType('data://text/plain,hello world')
        );
        $this->assertSame(
            'text/x-php',
            MimeContentTypeJitHelper::mimeContentType('data://text/plain;base64,'.\base64_encode('<?php echo 1;'))
        );
        $this->assertNull(MimeContentTypeJitHelper::mimeContentType('/no/such/phpc-mime-'.bin2hex(random_bytes(4))));

        @\unlink($path);
    }
}
