<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MimeContentTypeJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * mime_content_type JIT routes through MimeContentTypeJitHelper PHP (#9236 / #25544 / #33034).
 */
final class MimeContentTypeRuntimeShrinkTest extends TestCase
{
    public function testMimeContentTypeJitHelperIsNestedJitSafe(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/MimeContentTypeJitHelper.php');
        $this->assertStringContainsString('@\\file_get_contents', $source);
        $this->assertStringContainsString('self::detectFromBytes', $source);
        $this->assertStringNotContainsString('VmFs::', $source);
        $this->assertStringNotContainsString('VmMime::mimeContentTypeFromPath', $source);
        $this->assertStringNotContainsString('VmMime::detectFromBytes($data)', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\json_decode\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\strncmp\\s*\\(/', $source);
    }

    public function testMimeContentTypeRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MimeContentTypeRuntime.php');
        $this->assertStringContainsString('MimeContentTypeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(120, $lineCount);
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
        $this->assertNull(MimeContentTypeJitHelper::mimeContentType('/no/such-phpc-mime-'.bin2hex(random_bytes(4))));
        @\unlink($path);
    }
}
